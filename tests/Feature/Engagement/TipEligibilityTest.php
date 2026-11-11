<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\TipSetting;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Support\Engagement\Moments\CashSalesTodayReached;
use App\Support\Engagement\Moments\PendingMoments;
use App\Support\Engagement\TipEligibility;
use Spatie\Permission\Models\Role;

function eligibility(): TipEligibility
{
    return app(TipEligibility::class);
}

// ── Status, dates, panel, role ───────────────────────────────────────────────

it('never shows a draft, paused or out-of-window tip', function (array $attributes, string $reason) {
    $ctx = engagementVendor();
    $tip = makeTip($attributes);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe($reason)
        ->and(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
})->with([
    'draft' => [['status' => Tip::STATUS_DRAFT], 'not_live'],
    'paused' => [['status' => Tip::STATUS_PAUSED], 'not_live'],
    'not started' => [['starts_at' => now()->addDay()], 'not_live'],
    'ended' => [['ends_at' => now()->subMinute()], 'not_live'],
]);

it('shows an active tip inside its window', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']))?->tip->id)->toBe($tip->id);
});

it('never shows a tip in a panel it was not targeted at', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['panels' => ['admin']]);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('wrong_panel');
});

it('shows a role-targeted tip only to that role, with the owner as its own role', function () {
    $ctx = engagementVendor();
    $forStorekeepers = makeTip(['roles' => ['storekeeper']]);
    $forOwners = makeTip(['roles' => [Tip::ROLE_OWNER]]);

    expect(eligibility()->explain($forStorekeepers, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBeNull()
        ->and(eligibility()->explain($forStorekeepers, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('wrong_role')
        ->and(eligibility()->explain($forOwners, tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull()
        ->and(eligibility()->explain($forOwners, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('wrong_role');
});

it('does not count a role held at a different vendor', function () {
    $a = engagementVendor('A');
    $b = engagementVendor('B');
    // B's storekeeper is a plain member at A.
    $a['vendor']->users()->attach($b['storekeeper']->id);
    $tip = makeTip(['roles' => ['storekeeper']]);

    expect(eligibility()->explain($tip, tipsContext($b['storekeeper'], $a['vendor'])))->toBe('wrong_role');
});

// ── Pages ────────────────────────────────────────────────────────────────────

it('stays off create and edit pages unless the tip asks for them', function () {
    $ctx = engagementVendor();
    $tip = makeTip();
    $formTip = makeTip(['show_on_form_pages' => true]);

    foreach (['filament.vendor.resources.products.create', 'filament.vendor.resources.products.edit'] as $route) {
        expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'], $route)))->toBe('form_page')
            ->and(eligibility()->explain($formTip, tipsContext($ctx['owner'], $ctx['vendor'], $route)))->toBeNull();
    }
});

// ── Features and access ──────────────────────────────────────────────────────

it('skips people already using the linked feature', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts']);

    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBeNull();

    recordTestDebt($ctx, $ctx['storekeeper']);

    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('feature_already_used');
});

it('judges owners on the business and staff on their own use', function () {
    $ctx = engagementVendor();
    $colleague = User::factory()->create();
    $ctx['vendor']->users()->attach($colleague->id);
    setPermissionsTeamId($ctx['vendor']->id);
    $colleague->assignRole(Role::where('name', 'storekeeper')->where('team_id', $ctx['vendor']->id)->first());

    $tip = makeTip(['feature_key' => 'customer_debts']);
    recordTestDebt($ctx, $ctx['storekeeper']);

    // The owner's storekeeper sells on credit, so the owner's business uses it.
    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('feature_already_used')
        // A colleague who has never recorded a debt still gets the tip.
        ->and(eligibility()->explain($tip, tipsContext($colleague, $ctx['vendor'])))->toBeNull();
});

it('still shows the tip when the switch to hide it from users is off', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts', 'hide_if_feature_used' => false]);
    recordTestDebt($ctx, $ctx['owner']);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
});

it('never shows a feature tip to someone who cannot open the feature', function () {
    $ctx = engagementVendor();
    // Store settlement needs view_store_settlement; storekeepers lack it.
    $tip = makeTip(['feature_key' => 'settlement_statement']);

    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('no_feature_access')
        ->and(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
});

it('never shows a tip whose button leads somewhere the user cannot go', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['button_label' => 'Open', 'button_route' => 'filament.vendor.pages.store-settlement']);

    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('no_destination_access');

    $decision = eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']));
    expect($decision?->buttonUrl)->toBe(url('/plug/'.$ctx['vendor']->slug.'/store-settlement'));
});

it('refuses a button route that does not exist', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['button_route' => 'filament.vendor.pages.no-such-page']);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('no_destination_access');
});

it("defaults the button to the linked feature's page for this tenant", function () {
    $ctx = engagementVendor();
    makeTip(['feature_key' => 'customer_debts', 'button_label' => 'See who owes you']);

    $decision = eligibility()->pick(tipsContext($ctx['storekeeper'], $ctx['vendor']));

    expect($decision?->buttonUrl)->toBe(url('/plug/'.$ctx['vendor']->slug.'/customer-debts'));
});

// ── Conditions ───────────────────────────────────────────────────────────────

it('applies the smart conditions', function () {
    $ctx = engagementVendor();
    $noProducts = makeTip(['condition' => 'fewer_products', 'condition_value' => 1]);
    $newAccount = makeTip(['condition' => 'new_account', 'condition_value' => 30]);
    $unknown = makeTip(['condition' => 'nonsense']);
    $owner = tipsContext($ctx['owner'], $ctx['vendor']);

    expect(eligibility()->explain($noProducts, $owner))->toBeNull()
        ->and(eligibility()->explain($newAccount, $owner))->toBeNull()
        ->and(eligibility()->explain($unknown, $owner))->toBe('condition');

    Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => Category::create(['name' => 'C'.uniqid()])->id,
        'name' => 'Widget', 'price' => 100, 'cost_price' => 50, 'stock_quantity' => 1,
        'reserved_stock' => 0, 'status' => 'published',
    ]);
    $ctx['vendor']->forceFill(['created_at' => now()->subDays(60)])->save();

    $owner = tipsContext($ctx['owner'], $ctx['vendor']->fresh());

    expect(eligibility()->explain($noProducts, $owner))->toBe('condition')
        ->and(eligibility()->explain($newAccount, $owner))->toBe('condition');
});

// ── Frequency ────────────────────────────────────────────────────────────────

it('retires a tip once it is clicked or dismissed', function (string $event) {
    $ctx = engagementVendor();
    $tip = makeTip(['frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 0]);
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(10));
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], $event, now()->subDays(10));

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('retired');
})->with([TipEvent::CLICKED, TipEvent::DISMISSED]);

it('respects max views and the cooldown between them', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['frequency' => Tip::FREQUENCY_TIMES, 'max_views' => 2, 'cooldown_days' => 3]);

    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDay());
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SNOOZED, now()->subDay());
    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('cooldown');

    $this->travel(3)->days();
    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();

    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN);
    $this->travel(10)->days();
    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('max_views');
});

it('shows a show-once tip only once', function () {
    $ctx = engagementVendor();
    $tip = makeTip();
    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(30));

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('max_views');
});

it('shows an announcement once per person even if they never click it', function () {
    $ctx = engagementVendor();
    $announcement = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 0]);

    // The model forces announcements to show-once whatever the form sent.
    expect($announcement->frequency)->toBe(Tip::FREQUENCY_ONCE);

    logTipEvent($announcement, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(5));

    expect(eligibility()->explain($announcement, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('retired')
        ->and(eligibility()->explain($announcement, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBeNull();
});

it('shows announcements even to people already using the feature', function () {
    $ctx = engagementVendor();
    $announcement = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT, 'feature_key' => 'customer_debts']);
    recordTestDebt($ctx, $ctx['owner']);

    expect(eligibility()->explain($announcement, tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
});

// ── The daily slot ───────────────────────────────────────────────────────────

it('gives each person at most one pop-up a day', function () {
    $ctx = engagementVendor();
    $first = makeTip();
    makeTip();

    logTipEvent($first, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN);

    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();

    $this->travel(1)->days();
    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->not->toBeNull();
});

it('counts a guided tour offer as that day’s pop-up', function () {
    $ctx = engagementVendor();
    makeTip();

    UserTourProgress::record($ctx['owner']->id, $ctx['vendor']->id, 'record-procurement', 'offered');

    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
});

it('lets the admin raise the daily cap, or set it to zero to silence everything', function () {
    $ctx = engagementVendor();
    $first = makeTip();
    makeTip();
    logTipEvent($first, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN);

    TipSetting::current()->update(['daily_cap' => 2]);
    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->not->toBeNull();

    TipSetting::current()->update(['daily_cap' => 0]);
    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor'])))->toBeNull();
});

it('ranks announcement, then moment tip, then ordinary tip', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['priority' => 100]);
    $moment = makeTip(['moment' => CashSalesTodayReached::KEY, 'moment_threshold' => 5]);
    $announcement = makeTip(['type' => Tip::TYPE_ANNOUNCEMENT]);

    app(PendingMoments::class)->record($ctx['owner']->id, $ctx['vendor']->id, CashSalesTodayReached::KEY, 5, now()->addHour());

    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']))->tip->id)->toBe($announcement->id);

    $announcement->update(['status' => Tip::STATUS_PAUSED]);
    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']))->tip->id)->toBe($moment->id);

    $moment->update(['status' => Tip::STATUS_PAUSED]);
    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']))->tip->id)->toBe($tip->id);
});

it('breaks ties on priority, then on the tip seen least recently', function () {
    $ctx = engagementVendor();
    $low = makeTip(['priority' => 1, 'frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 1]);
    $seenLongAgo = makeTip(['priority' => 5, 'frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 1]);
    $seenRecently = makeTip(['priority' => 5, 'frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 1]);

    logTipEvent($seenLongAgo, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(20));
    logTipEvent($seenRecently, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(2));

    expect(eligibility()->pick(tipsContext($ctx['owner'], $ctx['vendor']))->tip->id)->toBe($seenLongAgo->id);
});

// ── Moments ──────────────────────────────────────────────────────────────────

it('shows a moment tip only while its moment is pending and past the threshold', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['moment' => CashSalesTodayReached::KEY, 'moment_threshold' => 5]);
    $pending = app(PendingMoments::class);

    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('no_moment');

    $pending->record($ctx['storekeeper']->id, $ctx['vendor']->id, CashSalesTodayReached::KEY, 4, now()->addMinutes(15));
    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('no_moment');

    $pending->record($ctx['storekeeper']->id, $ctx['vendor']->id, CashSalesTodayReached::KEY, 5, now()->addMinutes(15));
    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBeNull();

    $this->travel(16)->minutes();
    expect(eligibility()->explain($tip, tipsContext($ctx['storekeeper'], $ctx['vendor'])))->toBe('no_moment');
});

it('fires the empty debtors page moment on that page only', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['moment' => 'debtors_page_empty_visit']);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'])))->toBe('no_moment')
        ->and(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'], 'filament.vendor.resources.customer-debts.index')))->toBeNull();
});

it('does not fire the empty debtors moment once there are debts', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['moment' => 'debtors_page_empty_visit']);
    recordTestDebt($ctx, $ctx['owner']);

    expect(eligibility()->explain($tip, tipsContext($ctx['owner'], $ctx['vendor'], 'filament.vendor.resources.customer-debts.index')))->toBe('no_moment');
});

// ── Tenant isolation ─────────────────────────────────────────────────────────

it("never lets one vendor's data decide a tip at another", function () {
    $a = engagementVendor('A');
    $b = engagementVendor('B');
    // A's owner also works as a storekeeper at B.
    $b['vendor']->users()->attach($a['owner']->id);
    setPermissionsTeamId($b['vendor']->id);
    $a['owner']->assignRole(Role::where('name', 'storekeeper')->where('team_id', $b['vendor']->id)->first());
    $tip = makeTip(['feature_key' => 'customer_debts', 'frequency' => Tip::FREQUENCY_UNTIL_CLICKED, 'cooldown_days' => 0]);

    // At A: they use debts and have dismissed the tip, and a moment is pending.
    recordTestDebt($a, $a['owner']);
    logTipEvent($tip, $a['owner'], $a['vendor'], TipEvent::DISMISSED);
    app(PendingMoments::class)->record($a['owner']->id, $a['vendor']->id, CashSalesTodayReached::KEY, 9, now()->addHour());

    // At B none of that is true.
    expect(eligibility()->explain($tip, tipsContext($a['owner'], $b['vendor'])))->toBeNull()
        ->and(app(PendingMoments::class)->active($a['owner']->id, $b['vendor']->id))->toBe([]);
});
