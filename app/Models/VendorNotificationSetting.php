<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Per-vendor storekeeper notification preferences. One row per vendor, created
// on demand with the migration defaults so a store that has never opened the
// settings page still behaves sensibly.
class VendorNotificationSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'notify_new_order'         => 'boolean',
        'notify_undispatched'      => 'boolean',
        'notify_low_stock'         => 'boolean',
        'notify_cancelled'         => 'boolean',
        'notify_daily_summary'     => 'boolean',
        'quiet_hours_enabled'      => 'boolean',
        'undispatched_after_hours' => 'integer',
        'last_reminder_sent_at'    => 'datetime',
        'remind_orders_from'       => 'datetime',
        'last_daily_summary_for'   => 'date',
    ];

    public const FREQUENCIES = [
        'hourly' => 'Every hour',
        '3h'     => 'Every 3 hours',
        '6h'     => 'Every 6 hours',
        'daily'  => 'Once a day',
    ];

    private const FREQUENCY_HOURS = [
        'hourly' => 1,
        '3h'     => 3,
        '6h'     => 6,
        'daily'  => 24,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public static function forVendor(Vendor|int $vendor): self
    {
        $settings = static::firstOrCreate(
            ['vendor_id' => $vendor instanceof Vendor ? $vendor->id : $vendor],
            // Stamped once, on the row that represents this store switching the
            // feature on. Everything placed before this is history the storekeeper
            // is not going to be chased about.
            ['remind_orders_from' => now()],
        );

        // firstOrCreate inserts only vendor_id, so every column filled by a
        // database default comes back unset on the new instance — booleans read
        // false and undispatched_after_hours reads null. Without this refresh a
        // brand-new store would silently send no alerts despite the defaults
        // saying otherwise, and the settings form would fail its own validation.
        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    public function hasOwnerNumber(): bool
    {
        return filled($this->owner_whatsapp);
    }

    // Which business date, if any, the daily summary should cover right now.
    //
    // Returns the date to summarise, or null when nothing is due. The report
    // covers YESTERDAY, so the send time is a morning briefing about a day that
    // has definitely finished — a figure for a day still being traded would be
    // wrong the moment it was read.
    //
    // Deliberately "at or past the configured hour, and not yet sent for that
    // date" rather than "exactly this hour": the scheduler can miss a tick
    // (deploy, host hiccup, withoutOverlapping), and an exact match would then
    // silently skip the day entirely rather than sending an hour late.
    public function dailySummaryDueFor(CarbonInterface $now): ?CarbonInterface
    {
        if (! $this->notify_daily_summary || ! $this->hasOwnerNumber()) {
            return null;
        }

        // Read on the shop's clock, not the server's. app.timezone is UTC, so
        // comparing the configured hour against a UTC now() would send a Lagos
        // vendor's 07:00 summary at 08:00 local.
        $local = self::inBusinessTimezone($now);

        $minutesNow = ($local->hour * 60) + $local->minute;

        if ($minutesNow < $this->minutesOf($this->daily_summary_time)) {
            return null;
        }

        // Yesterday on the shop's calendar too — near midnight the server's date
        // and the shop's date are different days, and summarising the server's
        // "yesterday" would skip or repeat a day of trading.
        $covers = $local->copy()->subDay()->startOfDay();

        if ($this->last_daily_summary_for?->isSameDay($covers)) {
            return null;
        }

        return $covers;
    }

    public static function inBusinessTimezone(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(config('services.messaging.timezone', 'Africa/Lagos'));
    }

    public function hasStorekeeperNumber(): bool
    {
        return filled($this->storekeeper_whatsapp);
    }

    public function wantsAnyReminder(): bool
    {
        return $this->notify_undispatched || $this->notify_low_stock;
    }

    // Whether periodic reminders may go out right now: at least one recurring
    // alert must be switched on, the cadence must have elapsed, and if quiet
    // hours are on we must be inside the waking window. Which of the recurring
    // alerts actually sends is decided per alert, not here.
    public function reminderDueAt(CarbonInterface $now): bool
    {
        if (! $this->wantsAnyReminder() || ! $this->hasStorekeeperNumber()) {
            return false;
        }

        if ($this->isQuietAt($now)) {
            return false;
        }

        if ($this->last_reminder_sent_at === null) {
            return true;
        }

        $hours = self::FREQUENCY_HOURS[$this->reminder_frequency] ?? 3;

        // Half an hour of slack: the scheduler fires hourly on the host's clock,
        // so a strict >= comparison would skip a slot whenever the run drifts a
        // few seconds late and push a 3h cadence out to 4h.
        return $this->last_reminder_sent_at->diffInMinutes($now) >= ($hours * 60) - 30;
    }

    public function isQuietAt(CarbonInterface $now): bool
    {
        if (! $this->quiet_hours_enabled) {
            return false;
        }

        // Same reason as dailySummaryDueFor: quiet hours are the shop's hours.
        $local = self::inBusinessTimezone($now);

        $minutes = ($local->hour * 60) + $local->minute;
        $from    = $this->minutesOf($this->quiet_from);
        $until   = $this->minutesOf($this->quiet_until);

        // Equal bounds would otherwise silence the whole day.
        if ($from === $until) {
            return false;
        }

        // Waking window inside one calendar day (08:00–20:00): quiet outside it.
        if ($from < $until) {
            return $minutes < $from || $minutes >= $until;
        }

        // Window wraps midnight (e.g. 20:00–08:00 as the waking period): quiet
        // only in the gap between them.
        return $minutes >= $until && $minutes < $from;
    }

    private function minutesOf(?string $time): int
    {
        if (blank($time)) {
            return 0;
        }

        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour * 60) + (int) $minute;
    }
}
