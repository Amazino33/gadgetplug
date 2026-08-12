<?php

use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateReachBand;
use App\Models\AffiliateSetting;
use App\Models\AffiliateTask;
use App\Models\AffiliateTaskSubmission;
use App\Models\PlugPointTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use App\Services\Affiliate\DailySocialShareService;
use App\Services\Affiliate\PlugPointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function shareTask(array $overrides = []): AffiliateTask
{
    return AffiliateTask::create(array_merge([
        'name'              => 'Daily Share',
        'task_type'         => 'daily_social_share',
        'verification_type' => 'manual',
        'reward_amount'     => 0,
        'points_reward'     => 0,
        'is_active'         => true,
    ], $overrides));
}

function shareAffiliate(): Affiliate
{
    return Affiliate::findOrCreateForUser(User::factory()->create());
}

function reviewer(): User
{
    return User::factory()->create();
}

/** Midday inside the default 08:00–22:00 WAT window. */
function insideWindow(string $date = '2026-06-10'): Carbon
{
    return Carbon::parse($date . ' 12:00:00', 'Africa/Lagos')->utc();
}

beforeEach(function () {
    AffiliateSetting::current()->update([
        'share_timezone'          => 'Africa/Lagos',
        'share_window_opens_at'   => '08:00:00',
        'share_window_closes_at'  => '22:00:00',
        'daily_share_points_cap'  => 120,
        'streak_bonus_points'     => 50,
        'streak_bonus_every_days' => 7,
    ]);
});

// ─── Window ─────────────────────────────────────────────────────────────

test('a submission inside the WAT window is accepted', function () {
    Carbon::setTestNow(insideWindow());

    $submission = app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), 250);

    expect($submission->status)->toBe('submitted')
        ->and($submission->share_date->toDateString())->toBe('2026-06-10');
});

test('a submission before the window opens is rejected', function () {
    // 07:00 WAT — one hour early.
    Carbon::setTestNow(Carbon::parse('2026-06-10 07:00:00', 'Africa/Lagos')->utc());

    expect(fn () => app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), 250))
        ->toThrow(RuntimeException::class);
});

test('a submission after the window closes is rejected', function () {
    // 22:30 WAT.
    Carbon::setTestNow(Carbon::parse('2026-06-10 22:30:00', 'Africa/Lagos')->utc());

    expect(fn () => app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), 250))
        ->toThrow(RuntimeException::class);
});

test('the window is judged in WAT, not UTC — 23:00 UTC is midnight WAT and outside', function () {
    // 23:00 UTC on the 10th is 00:00 WAT on the 11th: inside an 08:00-22:00 UTC
    // reading, but correctly outside once WAT is applied.
    Carbon::setTestNow(Carbon::parse('2026-06-10 23:00:00', 'UTC'));

    expect(app(DailySocialShareService::class)->windowIsOpen())->toBeFalse();
});

test('a late-night share is dated by the WAT calendar day, not the UTC one', function () {
    // 21:30 WAT on the 10th = 20:30 UTC on the 10th. Same day either way.
    // The interesting case is 00:30 WAT on the 11th = 23:30 UTC on the 10th.
    Carbon::setTestNow(Carbon::parse('2026-06-11 00:30:00', 'Africa/Lagos')->utc());

    expect(app(DailySocialShareService::class)->shareDateFor()->toDateString())->toBe('2026-06-11');
});

// ─── Bands ──────────────────────────────────────────────────────────────

test('reported reach maps to the correct band and points', function (int $reach, string $band, int $points) {
    Carbon::setTestNow(insideWindow());

    $submission = app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), $reach);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    $submission->refresh();

    expect($submission->reachBand->name)->toContain($band)
        ->and($submission->points_awarded)->toBe($points);
})->with([
    [50,     'Starter', 5],
    [100,    'Growing', 15],
    [499,    'Growing', 15],
    [500,    'Solid',   30],
    [5000,   'Strong',  60],
    [250000, 'Viral',   100],
]);

test('a reach no active band covers awards nothing but still settles', function () {
    Carbon::setTestNow(insideWindow());
    AffiliateReachBand::query()->update(['is_active' => false]);

    $submission = app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), 500);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    $submission->refresh();

    expect($submission->status)->toBe('approved')
        ->and($submission->points_awarded)->toBe(0)
        ->and(app(PlugPointService::class)->balance($submission->affiliate_id))->toBe(0);
});

// ─── Crediting & idempotency ────────────────────────────────────────────

test('points are credited only on approval', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 5000);

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(0);

    app(DailySocialShareService::class)->approve($submission, reviewer());

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(60);
});

test('approving twice credits once', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 5000);
    $service    = app(DailySocialShareService::class);

    $service->approve($submission, reviewer());
    $service->approve($submission->fresh(), reviewer());

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(60)
        ->and(PlugPointTransaction::where('affiliate_id', $affiliate->id)->count())->toBe(1);
});

test('rejecting credits nothing and records the reason', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 5000);

    app(DailySocialShareService::class)->reject($submission, reviewer(), 'Screenshot does not show your code.');

    $submission->refresh();

    expect($submission->status)->toBe('rejected')
        ->and($submission->rejected_reason)->toBe('Screenshot does not show your code.')
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(0)
        ->and(PlugPointTransaction::count())->toBe(0);
});

test('a share never touches the wallet — points and cash stay separate', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 250000);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    expect(WalletTransaction::count())->toBe(0);
});

// ─── Daily cap ──────────────────────────────────────────────────────────

test('the daily cap is never exceeded across multiple submissions in one day', function () {
    AffiliateSetting::current()->update(['daily_share_points_cap' => 100]);
    Carbon::setTestNow(insideWindow());

    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    // Three Viral shares at 100 points each would be 300 without a cap.
    foreach (range(1, 3) as $i) {
        $submission = $service->submit($task, $affiliate, 250000);
        $service->approve($submission, reviewer());
    }

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(100);
});

test('the cap pays the remainder rather than overshooting or refusing', function () {
    AffiliateSetting::current()->update(['daily_share_points_cap' => 45]);
    Carbon::setTestNow(insideWindow());

    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    // 30 (Solid) then 60 (Strong) → second is trimmed to the remaining 15.
    $first = $service->submit($task, $affiliate, 500);
    $service->approve($first, reviewer());

    $second = $service->submit($task, $affiliate, 5000);
    $service->approve($second, reviewer());

    expect($first->fresh()->points_awarded)->toBe(30)
        ->and($second->fresh()->points_awarded)->toBe(15)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(45);
});

test('the cap resets on the next day', function () {
    AffiliateSetting::current()->update(['daily_share_points_cap' => 30]);

    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    Carbon::setTestNow(insideWindow('2026-06-10'));
    $service->approve($service->submit($task, $affiliate, 500), reviewer());

    Carbon::setTestNow(insideWindow('2026-06-11'));
    $service->approve($service->submit($task, $affiliate, 500), reviewer());

    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(60);
});

// ─── Streak ─────────────────────────────────────────────────────────────

test('consecutive days increment the streak', function () {
    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    foreach (['2026-06-10', '2026-06-11', '2026-06-12'] as $day) {
        Carbon::setTestNow(insideWindow($day));
        $service->approve($service->submit($task, $affiliate, 500), reviewer());
    }

    Carbon::setTestNow(insideWindow('2026-06-12'));

    expect($service->currentStreak($affiliate->id))->toBe(3);
});

test('missing a day resets the streak to zero, and the next share starts again at one', function () {
    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    Carbon::setTestNow(insideWindow('2026-06-10'));
    $service->approve($service->submit($task, $affiliate, 500), reviewer());

    Carbon::setTestNow(insideWindow('2026-06-11'));
    $service->approve($service->submit($task, $affiliate, 500), reviewer());

    // 12th skipped entirely.
    Carbon::setTestNow(insideWindow('2026-06-13'));
    expect($service->currentStreak($affiliate->id))->toBe(0);

    $resumed = $service->submit($task, $affiliate, 500);
    $service->approve($resumed, reviewer());

    expect($resumed->fresh()->streak_day)->toBe(1)
        ->and($service->currentStreak($affiliate->id))->toBe(1);
});

test('the streak bonus lands on every Nth consecutive day', function () {
    AffiliateSetting::current()->update([
        'streak_bonus_every_days' => 3,
        'streak_bonus_points'     => 25,
        'daily_share_points_cap'  => 1000,
    ]);

    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    $awarded = [];

    foreach (['2026-06-10', '2026-06-11', '2026-06-12', '2026-06-13'] as $day) {
        Carbon::setTestNow(insideWindow($day));
        $submission = $service->submit($task, $affiliate, 500);
        $service->approve($submission, reviewer());
        $awarded[] = $submission->fresh()->streak_bonus_points;
    }

    // Day 3 is the only multiple of 3 in the first four days.
    expect($awarded)->toBe([0, 0, 25, 0]);

    // 4 days x 30 band points + one 25 bonus.
    expect(app(PlugPointService::class)->balance($affiliate->id))->toBe(145);
});

test('a rejected day breaks the streak exactly as a missed day does', function () {
    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    Carbon::setTestNow(insideWindow('2026-06-10'));
    $service->approve($service->submit($task, $affiliate, 500), reviewer());

    Carbon::setTestNow(insideWindow('2026-06-11'));
    $service->reject($service->submit($task, $affiliate, 500), reviewer(), 'No code visible.');

    Carbon::setTestNow(insideWindow('2026-06-12'));
    $resumed = $service->submit($task, $affiliate, 500);
    $service->approve($resumed, reviewer());

    expect($resumed->fresh()->streak_day)->toBe(1);
});

// ─── Settings only affect the future ────────────────────────────────────

test('retuning bands and cap later never rewrites a settled award', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 5000);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    $frozenPoints = $submission->fresh()->points_awarded;
    $frozenBand   = $submission->fresh()->affiliate_reach_band_id;

    // Move the goalposts hard.
    AffiliateReachBand::query()->update(['points' => 1]);
    AffiliateSetting::current()->update(['daily_share_points_cap' => 0]);

    expect($submission->fresh()->points_awarded)->toBe($frozenPoints)
        ->and($submission->fresh()->affiliate_reach_band_id)->toBe($frozenBand)
        ->and(app(PlugPointService::class)->balance($affiliate->id))->toBe(60);
});

test('a submission awaiting review is judged by the settings in force when it is approved', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit(shareTask(), $affiliate, 5000);

    // Band retuned while the submission sits in the queue. The cap is lifted
    // too, so what this measures is the band change rather than the cap
    // trimming the award back down to 120.
    AffiliateReachBand::where('name', 'like', 'Strong%')->update(['points' => 999]);
    AffiliateSetting::current()->update(['daily_share_points_cap' => 5000]);

    app(DailySocialShareService::class)->approve($submission, reviewer());

    expect($submission->fresh()->points_awarded)->toBe(999);
});

// ─── Level progression hook ─────────────────────────────────────────────

test('a share flagged to count toward level freezes its progress value', function () {
    Carbon::setTestNow(insideWindow());

    $task = shareTask(['counts_toward_level' => true, 'level_progress_value' => 5000]);

    $affiliate  = shareAffiliate();
    $submission = app(DailySocialShareService::class)->submit($task, $affiliate, 500);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    expect((float) $submission->fresh()->level_progress_value)->toBe(5000.0);

    // A later edit to the task must not restate the completed submission.
    $task->update(['level_progress_value' => 1]);

    expect((float) $submission->fresh()->level_progress_value)->toBe(5000.0);
});

test('an approved share actually advances the affiliate up a level', function () {
    Carbon::setTestNow(insideWindow());

    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,     'rate_value' => 1.0, 'sort_order' => 0, 'is_active' => true]);
    AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1, 'is_active' => true]);

    $task      = shareTask(['counts_toward_level' => true, 'level_progress_value' => 50000]);
    $affiliate = shareAffiliate();

    app(DailySocialShareService::class)->approve(
        app(DailySocialShareService::class)->submit($task, $affiliate, 500),
        reviewer(),
    );

    expect($affiliate->fresh()->level->name)->toBe('Silver')
        ->and(app(AffiliateLevelProgressionService::class)->taskProgressValue($affiliate->id))->toBe(50000.0);
});

test('the level ratchet holds — a later rejection never pulls the level back down', function () {
    AffiliateLevel::create(['name' => 'Bronze', 'target' => 0,     'rate_value' => 1.0, 'sort_order' => 0, 'is_active' => true]);
    AffiliateLevel::create(['name' => 'Silver', 'target' => 50000, 'rate_value' => 1.1, 'sort_order' => 1, 'is_active' => true]);

    $task      = shareTask(['counts_toward_level' => true, 'level_progress_value' => 50000]);
    $affiliate = shareAffiliate();
    $service   = app(DailySocialShareService::class);

    Carbon::setTestNow(insideWindow('2026-06-10'));
    $promoting = $service->submit($task, $affiliate, 500);
    $service->approve($promoting, reviewer());

    expect($affiliate->fresh()->level->name)->toBe('Silver');

    // Strip the progress back out, then recompute: progression is a ratchet, so
    // the stored level must not fall even though the derived value now would.
    $promoting->fresh()->update(['status' => 'rejected', 'level_progress_value' => null]);

    app(AffiliateLevelProgressionService::class)->recompute($affiliate->fresh());

    expect($affiliate->fresh()->level->name)->toBe('Silver')
        ->and(app(AffiliateLevelProgressionService::class)->taskProgressValue($affiliate->id))->toBe(0.0);
});

test('an approved share counts as qualifying activity against the demotion clock', function () {
    Carbon::setTestNow(insideWindow());

    $task      = shareTask(['counts_toward_level' => true, 'level_progress_value' => 100]);
    $affiliate = shareAffiliate();

    app(DailySocialShareService::class)->approve(
        app(DailySocialShareService::class)->submit($task, $affiliate, 500),
        reviewer(),
    );

    // An affiliate who only does shares and never sells must not read as
    // inactive — lastQualifyingActivityAt counts approved task submissions.
    expect(app(AffiliateLevelProgressionService::class)->lastQualifyingActivityAt($affiliate->id))
        ->not->toBeNull();
});

test('a share not flagged for level progress contributes nothing to it', function () {
    Carbon::setTestNow(insideWindow());

    $submission = app(DailySocialShareService::class)->submit(shareTask(), shareAffiliate(), 500);
    app(DailySocialShareService::class)->approve($submission, reviewer());

    expect($submission->fresh()->level_progress_value)->toBeNull();
});

test('an ordinary manual task cannot be submitted through the share form', function () {
    Carbon::setTestNow(insideWindow());

    $task = AffiliateTask::create([
        'name' => 'Ordinary', 'task_type' => 'manual', 'verification_type' => 'manual',
        'reward_amount' => 0, 'points_reward' => 10, 'is_active' => true,
    ]);

    expect(fn () => app(DailySocialShareService::class)->submit($task, shareAffiliate(), 100))
        ->toThrow(RuntimeException::class);
});

test('a second share cannot be submitted while one is still awaiting review that day', function () {
    Carbon::setTestNow(insideWindow());

    $affiliate = shareAffiliate();
    $task      = shareTask();
    $service   = app(DailySocialShareService::class);

    $service->submit($task, $affiliate, 500);

    expect(fn () => $service->submit($task, $affiliate, 500))->toThrow(RuntimeException::class);

    expect(AffiliateTaskSubmission::count())->toBe(1);
});
