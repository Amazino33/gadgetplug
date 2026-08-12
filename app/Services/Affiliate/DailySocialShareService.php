<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateReachBand;
use App\Models\AffiliateSetting;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The daily social-share task.
 *
 * Everything that decides a reward is admin-configured data: the submission
 * window and its timezone, the reach bands, the daily points cap, and the
 * streak bonus. Nothing here is a hardcoded number.
 *
 * Reward basis is coarse reach BANDS, never the exact self-reported count — a
 * screenshot's view counter is trivially forged, so the design pays in wide
 * buckets where lying is worth little, capped per day, against material that
 * carries the affiliate's own code.
 *
 * Band, points and streak are resolved at APPROVAL and frozen onto the
 * submission. Retuning the settings tomorrow changes only what happens next;
 * it never restates a settled award or rewrites a ledger row.
 */
class DailySocialShareService
{
    public const TASK_TYPE = 'daily_social_share';

    public function __construct(
        private PlugPointService $points,
        private AffiliateLevelProgressionService $levels,
    ) {}

    // ─── Window ─────────────────────────────────────────────────────────

    /**
     * "Today" in the configured share timezone. The app runs on UTC, so a
     * submission at 00:30 WAT belongs to the previous UTC day — deriving the
     * date without an explicit zone would silently mis-bucket every late-night
     * share and corrupt both the cap and the streak.
     */
    public function shareDateFor(?CarbonInterface $moment = null): CarbonInterface
    {
        $zone = AffiliateSetting::current()->share_timezone;

        return ($moment ?: now())->setTimezone($zone)->startOfDay();
    }

    public function windowIsOpen(?CarbonInterface $moment = null): bool
    {
        $settings = AffiliateSetting::current();
        $local    = ($moment ?: now())->setTimezone($settings->share_timezone);

        $opens  = $this->timeOnDay($local, $settings->share_window_opens_at);
        $closes = $this->timeOnDay($local, $settings->share_window_closes_at);

        // A window that closes before it opens wraps past midnight (e.g.
        // 20:00–02:00) — inside means "after open OR before close".
        if ($closes->lessThanOrEqualTo($opens)) {
            return $local->greaterThanOrEqualTo($opens) || $local->lessThanOrEqualTo($closes);
        }

        return $local->betweenIncluded($opens, $closes);
    }

    private function timeOnDay(CarbonInterface $local, string $time): CarbonInterface
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '0');

        // The app runs on CarbonImmutable (AppServiceProvider), so setTime
        // returns a new instance rather than mutating — always use the result.
        return $local->setTime((int) $h, (int) $m, (int) $s);
    }

    public function windowDescription(): string
    {
        $settings = AffiliateSetting::current();

        return Carbon::parse($settings->share_window_opens_at)->format('g:i A')
            . ' – ' . Carbon::parse($settings->share_window_closes_at)->format('g:i A')
            . ' (' . $settings->share_timezone . ')';
    }

    // ─── Submission ─────────────────────────────────────────────────────

    /**
     * Records today's share. Proof media is attached by the caller (Livewire
     * owns the upload), so this stays a pure domain operation.
     */
    public function submit(AffiliateTask $task, Affiliate $affiliate, int $reportedReach, ?string $notes = null): AffiliateTaskSubmission
    {
        if ($task->task_type !== self::TASK_TYPE) {
            throw new RuntimeException('That task is not a daily social share.');
        }

        if (! $task->is_active) {
            throw new RuntimeException('This task is no longer active.');
        }

        if (! $this->windowIsOpen()) {
            throw new RuntimeException('Today\'s submission window is closed. It opens ' . $this->windowDescription() . '.');
        }

        if ($reportedReach < 0) {
            throw new RuntimeException('Reach cannot be negative.');
        }

        $shareDate = $this->shareDateFor();

        if ($this->hasPendingFor($task, $affiliate, $shareDate)) {
            throw new RuntimeException('You already have a share awaiting review for today.');
        }

        return AffiliateTaskSubmission::create([
            'affiliate_task_id' => $task->id,
            'affiliate_id'      => $affiliate->id,
            'status'            => 'submitted',
            'notes'             => $notes,
            'submitted_at'      => now(),
            'share_date'        => $shareDate->toDateString(),
            'reported_reach'    => $reportedReach,
        ]);
    }

    public function hasPendingFor(AffiliateTask $task, Affiliate $affiliate, CarbonInterface $shareDate): bool
    {
        return AffiliateTaskSubmission::where('affiliate_task_id', $task->id)
            ->where('affiliate_id', $affiliate->id)
            ->whereDate('share_date', $shareDate->toDateString())
            ->where('status', 'submitted')
            ->exists();
    }

    // ─── Review ─────────────────────────────────────────────────────────

    /**
     * Approves a share and credits the points it earned, exactly once.
     *
     * Idempotency is structural rather than checked-then-done: the row is
     * re-read under a lock filtered on status 'submitted', so a second
     * approval finds nothing and returns without writing.
     */
    public function approve(AffiliateTaskSubmission $submission, User $reviewer): void
    {
        DB::transaction(function () use ($submission, $reviewer) {
            $locked = AffiliateTaskSubmission::where('id', $submission->id)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $settings  = AffiliateSetting::current();
            $task      = $locked->task;
            $shareDate = Carbon::parse($locked->share_date);

            $band       = AffiliateReachBand::forReach((int) $locked->reported_reach);
            $bandPoints = $band?->points ?? 0;

            // The daily cap is applied against what this affiliate has already
            // been awarded for this share date, so several submissions in one
            // day can never sum past it. Partial award rather than refusal:
            // the cap is a ceiling on spend, not a reason to void the work.
            $alreadyToday = $this->pointsAwardedOn($locked->affiliate_id, $shareDate);
            $remaining    = max((int) $settings->daily_share_points_cap - $alreadyToday, 0);
            $awarded      = min($bandPoints, $remaining);

            // Streak counts consecutive days with an approved share. It is
            // computed BEFORE this row is marked approved, then frozen.
            $streakDay   = $this->streakDayFor($locked->affiliate_id, $shareDate);
            $bonusEvery  = (int) $settings->streak_bonus_every_days;
            $bonusPoints = ($bonusEvery > 0 && $streakDay % $bonusEvery === 0)
                ? (int) $settings->streak_bonus_points
                : 0;

            $locked->update([
                'status'                  => 'approved',
                'reviewed_at'             => now(),
                'reviewed_by'             => $reviewer->id,
                'affiliate_reach_band_id' => $band?->id,
                'points_awarded'          => $awarded,
                'streak_day'              => $streakDay,
                'streak_bonus_points'     => $bonusPoints,
                'level_progress_value'    => $task->counts_toward_level ? $task->level_progress_value : null,
            ]);

            $bandName = $band?->name ?? 'no matching band';

            $this->points->creditForSubmission(
                $locked,
                $awarded,
                'daily_share',
                "Daily share — {$bandName} (submission #{$locked->id}).",
            );

            // Separate ledger row, separate source: the bonus is a distinct
            // thing the affiliate earned and should be able to see on its own.
            $this->points->creditForSubmission(
                $locked,
                $bonusPoints,
                'streak_bonus',
                "{$streakDay}-day streak bonus (submission #{$locked->id}).",
            );

            if ($task->counts_toward_level) {
                $this->levels->recompute($locked->affiliate);
            }

            activity()
                ->causedBy($reviewer)
                ->performedOn($locked)
                ->withProperties([
                    'band'        => $bandName,
                    'points'      => $awarded,
                    'streak_day'  => $streakDay,
                    'bonus'       => $bonusPoints,
                ])
                ->log('Daily share approved');
        });
    }

    public function reject(AffiliateTaskSubmission $submission, User $reviewer, string $reason): void
    {
        DB::transaction(function () use ($submission, $reviewer, $reason) {
            $locked = AffiliateTaskSubmission::where('id', $submission->id)
                ->where('status', 'submitted')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            // No points, no band, no streak advance — a rejected day breaks the
            // chain exactly as a missed day does.
            $locked->update([
                'status'          => 'rejected',
                'reviewed_at'     => now(),
                'reviewed_by'     => $reviewer->id,
                'rejected_reason' => $reason,
                'points_awarded'  => 0,
            ]);

            activity()
                ->causedBy($reviewer)
                ->performedOn($locked)
                ->withProperties(['reason' => $reason])
                ->log('Daily share rejected');
        });
    }

    // ─── Derived reads ──────────────────────────────────────────────────

    public function pointsAwardedOn(int $affiliateId, CarbonInterface $shareDate): int
    {
        return (int) AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->whereDate('share_date', $shareDate->toDateString())
            ->where('status', 'approved')
            ->sum('points_awarded');
    }

    /**
     * What streak day a share on `$shareDate` represents: one more than the
     * streak that ended yesterday, or 1 if yesterday had no approved share.
     * That "or 1" IS the reset — a missed day leaves nothing to extend.
     */
    public function streakDayFor(int $affiliateId, CarbonInterface $shareDate): int
    {
        // Same-day second approval doesn't advance the streak; a day is a day.
        $todayAlready = AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->whereDate('share_date', $shareDate->toDateString())
            ->where('status', 'approved')
            ->max('streak_day');

        if ($todayAlready) {
            return (int) $todayAlready;
        }

        $yesterday = AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->whereDate('share_date', $shareDate->copy()->subDay()->toDateString())
            ->where('status', 'approved')
            ->max('streak_day');

        return $yesterday ? (int) $yesterday + 1 : 1;
    }

    /**
     * The affiliate's live streak. Reads zero once a day has been missed,
     * without needing a scheduled job to zero a stored counter.
     */
    public function currentStreak(int $affiliateId): int
    {
        $latest = AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->where('status', 'approved')
            ->whereNotNull('share_date')
            ->orderByDesc('share_date')
            ->first();

        if (! $latest) {
            return 0;
        }

        $today     = $this->shareDateFor();
        $lastShare = Carbon::parse($latest->share_date)->startOfDay();

        // Today or yesterday keeps the chain alive — today's share may simply
        // not have happened yet.
        if ($lastShare->diffInDays($today) > 1) {
            return 0;
        }

        return (int) $latest->streak_day;
    }
}
