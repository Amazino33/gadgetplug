<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Models\PlatformMessagingSetting;
use App\Models\User;
use App\Services\Orders\DispatchMessages;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps automated WhatsApp safe while it rides a Wawp session — a real
 * WhatsApp number that gets banned if it behaves like a bulk sender.
 *
 * decide() answers, for one queued message, what to do right now:
 *   send — go;
 *   wait — not yet (the job is released back to the queue for that long);
 *   hold — WhatsApp is paused; kept until an admin resumes;
 *   sms  — urgent and WhatsApp can't take it (paused or at a cap): send by SMS.
 *
 * Rules, all from config/messaging.php:
 *   - lanes: P1 after a random 3–8s since the last WhatsApp send, P2 10–25s,
 *     P3 30–60s; the gap is rolled once per message, never a fixed interval;
 *   - per number: at least 30s between two messages;
 *   - P3 only inside its daytime window;
 *   - hourly and daily caps (a lower daily cap while warming up): at a cap P2
 *     and P3 wait for the next hour/day, P1 goes by SMS;
 *   - the brake: a session error, or N failures in a row, pauses all WhatsApp,
 *     alerts admins, and sends P1 by SMS until an admin resumes.
 *
 * Reservation happens under a lock: a message that is cleared to send claims
 * its slot (the global "last send" and its number's) before it sends, so two
 * workers can never both decide the slot is free.
 */
class WhatsAppGate
{
    private const LAST_SEND_KEY = 'whatsapp-gate:last-send';

    private const RECIPIENT_KEY = 'whatsapp-gate:to:';

    private const LOCK_KEY = 'whatsapp-gate:lock';

    /** @return array{action: string, seconds?: int, reason?: string} */
    public function decide(DeliveryMessage $message, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance(($now ?? now())->toDateTime());
        $lane = (int) $message->priority;

        if ($this->isPaused()) {
            return $lane === DeliveryMessage::P1
                ? ['action' => 'sms', 'reason' => 'whatsapp_paused']
                : ['action' => 'hold', 'reason' => 'whatsapp_paused'];
        }

        if ($lane === DeliveryMessage::P3 && ($wait = $this->secondsUntilP3Window($now)) > 0) {
            return ['action' => 'wait', 'seconds' => $wait, 'reason' => 'outside_p3_hours'];
        }

        if ($cap = $this->capReached($now)) {
            return $lane === DeliveryMessage::P1
                ? ['action' => 'sms', 'reason' => $cap['reason']]
                : ['action' => 'wait', 'seconds' => $cap['seconds'], 'reason' => $cap['reason']];
        }

        $gap = $this->gapFor($message);

        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(5, function () use ($message, $now, $gap) {
                $lastAny = (float) Cache::get(self::LAST_SEND_KEY, 0);
                $lastToThis = max(
                    (float) Cache::get(self::RECIPIENT_KEY.$message->to_number, 0),
                    (float) $this->lastSentToNumber($message->to_number),
                );

                $nowTs = $now->getTimestamp();
                $waitGlobal = (int) ceil($lastAny + $gap - $nowTs);
                $waitNumber = (int) ceil($lastToThis + (int) config('messaging.throttle.per_recipient_gap', 30) - $nowTs);
                $wait = max($waitGlobal, $waitNumber);

                if ($wait > 0) {
                    return [
                        'action'  => 'wait',
                        'seconds' => $wait + random_int(0, 2),
                        'reason'  => $waitNumber >= $waitGlobal ? 'per_recipient_gap' : 'lane_gap',
                    ];
                }

                // Claim the slot before sending.
                Cache::put(self::LAST_SEND_KEY, $nowTs, now()->addDay());
                Cache::put(self::RECIPIENT_KEY.$message->to_number, $nowTs, now()->addDay());

                return ['action' => 'send'];
            });
        } catch (Throwable $e) {
            // Could not get the lock in time: try again shortly rather than
            // risk two sends in the same slot.
            return ['action' => 'wait', 'seconds' => random_int(3, 8), 'reason' => 'busy'];
        }
    }

    /** The message's own random gap, rolled once and kept so retries don't re-roll toward the low end. */
    public function gapFor(DeliveryMessage $message): int
    {
        if ($message->throttle_gap_seconds !== null) {
            return (int) $message->throttle_gap_seconds;
        }

        [$min, $max] = config('messaging.throttle.lanes.'.(int) $message->priority, [10, 25]);
        $gap = random_int((int) $min, (int) max($min, $max));

        $message->forceFill(['throttle_gap_seconds' => $gap])->saveQuietly();

        return $gap;
    }

    // ── Caps ────────────────────────────────────────────────────────────────

    public function dailyCap(): int
    {
        return (bool) config('messaging.throttle.warmup')
            ? (int) config('messaging.throttle.warmup_daily_cap')
            : (int) config('messaging.throttle.daily_cap');
    }

    /** @return array{reason: string, seconds: int}|null */
    private function capReached(Carbon $now): ?array
    {
        $local = $now->copy()->setTimezone($this->timezone());

        if ($this->sentSince($local->copy()->startOfDay()) >= $this->dailyCap()) {
            return ['reason' => 'daily_cap', 'seconds' => (int) $now->diffInSeconds($local->copy()->addDay()->startOfDay(), true) + random_int(0, 300)];
        }

        if ($this->sentSince($local->copy()->startOfHour()) >= (int) config('messaging.throttle.hourly_cap')) {
            return ['reason' => 'hourly_cap', 'seconds' => (int) $now->diffInSeconds($local->copy()->addHour()->startOfHour(), true) + random_int(0, 120)];
        }

        return null;
    }

    public function sentSince(CarbonInterface $since): int
    {
        return DeliveryMessage::where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->where('sent_at', '>=', $since->copy()->setTimezone(config('app.timezone')))
            ->count();
    }

    private function lastSentToNumber(string $number): float
    {
        $at = DeliveryMessage::where('to_number', $number)
            ->where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->max('sent_at');

        return $at ? (float) Carbon::parse($at)->getTimestamp() : 0.0;
    }

    // ── P3 hours ────────────────────────────────────────────────────────────

    public function secondsUntilP3Window(CarbonInterface $now): int
    {
        [$from, $until] = config('messaging.throttle.p3_window', ['08:00', '20:00']);
        $local = Carbon::instance($now->toDateTime())->setTimezone($this->timezone());

        $start = $local->copy()->setTimeFromTimeString($from);
        $end = $local->copy()->setTimeFromTimeString($until);

        if ($local->gte($start) && $local->lt($end)) {
            return 0;
        }

        $next = $local->lt($start) ? $start : $start->copy()->addDay();

        // Spread the morning's backlog rather than releasing it all at 08:00.
        return (int) $local->diffInSeconds($next, true) + random_int(0, 600);
    }

    private function timezone(): string
    {
        return (string) config('messaging.throttle.timezone', 'Africa/Lagos');
    }

    // ── The brake ───────────────────────────────────────────────────────────

    public function isPaused(): bool
    {
        return PlatformMessagingSetting::current()->whatsapp_paused_at !== null;
    }

    public function recordSuccess(): void
    {
        PlatformMessagingSetting::current()->update([
            'whatsapp_consecutive_failures' => 0,
            'whatsapp_last_success_at'      => now(),
        ]);
    }

    /** @return bool whether this failure tripped the brake */
    public function recordFailure(?array $providerResponse): bool
    {
        $settings = PlatformMessagingSetting::current();
        $failures = (int) $settings->whatsapp_consecutive_failures + 1;

        $settings->update([
            'whatsapp_consecutive_failures' => $failures,
            'whatsapp_last_failure_at'      => now(),
        ]);

        if ($settings->whatsapp_paused_at !== null) {
            return false;
        }

        if ($sessionError = $this->sessionError($providerResponse)) {
            $this->pause("WhatsApp session error: {$sessionError}. Re-scan the QR code in Wawp, then resume.");

            return true;
        }

        $limit = (int) config('messaging.brake.consecutive_failures', 5);

        if ($failures >= $limit) {
            $this->pause("{$failures} WhatsApp sends failed in a row. Check the Wawp session, then resume.");

            return true;
        }

        return false;
    }

    private function sessionError(?array $providerResponse): ?string
    {
        $haystack = mb_strtolower(json_encode($providerResponse ?? []) ?: '');

        foreach ((array) config('messaging.brake.session_errors', []) as $code) {
            if (str_contains($haystack, mb_strtolower($code))) {
                return $code;
            }
        }

        return null;
    }

    /** Stop all WhatsApp sending and tell the people who can fix it. */
    public function pause(string $reason): void
    {
        $settings = PlatformMessagingSetting::current();

        if ($settings->whatsapp_paused_at !== null) {
            return;
        }

        $settings->update(['whatsapp_paused_at' => now(), 'whatsapp_pause_reason' => $reason]);

        Log::warning('WhatsApp sending paused.', ['reason' => $reason]);

        foreach (DispatchMessages::dispatchStaff() as $user) {
            Notification::make()
                ->title('WhatsApp sending is paused')
                ->body($reason.' Urgent customer messages are going by SMS meanwhile.')
                ->icon('heroicon-o-pause-circle')
                ->danger()
                ->sendToDatabase($user);
        }

        $this->smsOps('GadgetPlug: WhatsApp sending is PAUSED. '.$reason.' Urgent customer messages are going by SMS. Resume from the admin panel after re-scanning.');
    }

    /**
     * An admin has re-scanned the session: start again. Everything held while
     * paused goes back on the queue, through the same throttle.
     *
     * @return int how many held messages were re-queued
     */
    public function resume(?User $by = null): int
    {
        PlatformMessagingSetting::current()->update([
            'whatsapp_paused_at'            => null,
            'whatsapp_pause_reason'         => null,
            'whatsapp_consecutive_failures' => 0,
        ]);

        Log::info('WhatsApp sending resumed.', ['by' => $by?->id]);

        $held = DeliveryMessage::where('status', DeliveryMessage::STATUS_HELD)->orderBy('id')->get();

        foreach ($held as $message) {
            $message->update(['status' => 'queued']);
            app(MessagingService::class)->dispatch($message);
        }

        return $held->count();
    }

    private function smsOps(string $text): void
    {
        $to = config('messaging.ops_number');

        if (blank($to)) {
            Log::warning('No MESSAGING_OPS_NUMBER set — the WhatsApp brake alert went to the panel only.');

            return;
        }

        try {
            $message = DeliveryMessage::create([
                'vendor_id'      => null,
                'recipient_type' => 'ops',
                'channel'        => 'sms',
                'to_number'      => $to,
                'body'           => $text,
                'status'         => 'queued',
                'template_key'   => 'ops_whatsapp_paused',
                'priority'       => DeliveryMessage::P1,
            ]);

            app(MessagingService::class)->send($message);
        } catch (Throwable $e) {
            Log::error('Could not SMS the ops number about the WhatsApp pause: '.$e->getMessage());
        }
    }

    // ── For the dashboard ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function health(): array
    {
        $settings = PlatformMessagingSetting::current();
        $todayStart = now()->setTimezone($this->timezone())->startOfDay();

        return [
            'paused'        => $settings->whatsapp_paused_at !== null,
            'paused_at'     => $settings->whatsapp_paused_at,
            'pause_reason'  => $settings->whatsapp_pause_reason,
            'last_success'  => $settings->whatsapp_last_success_at,
            'failures_row'  => (int) $settings->whatsapp_consecutive_failures,
            'failures_today' => DeliveryMessage::where('channel', 'whatsapp')->where('status', 'failed')
                ->where('updated_at', '>=', $todayStart->copy()->setTimezone(config('app.timezone')))->count(),
            'sent_today'    => $this->sentSince($todayStart),
            'daily_cap'     => $this->dailyCap(),
            'warmup'        => (bool) config('messaging.throttle.warmup'),
            'held'          => DeliveryMessage::where('status', DeliveryMessage::STATUS_HELD)->count(),
        ];
    }
}
