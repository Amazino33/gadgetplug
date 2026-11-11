<?php

use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\TipSetting;
use App\Models\User;
use App\Support\Engagement\TipAdoption;

function adoptionFor(Tip $tip): array
{
    return app(TipAdoption::class)->summary($tip);
}

function adoptionStatusOf(Tip $tip, User $user): ?string
{
    return app(TipAdoption::class)->rows($tip)->firstWhere('user_id', $user->id)['status'] ?? null;
}

it('counts a person who used the feature after seeing the tip as Used', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts', 'adoption_window_days' => 7]);

    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(3));
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::CLICKED, now()->subDays(3));
    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(2));

    expect(adoptionStatusOf($tip, $ctx['storekeeper']))->toBe(TipAdoption::USED)
        ->and(adoptionFor($tip))->toBe(['saw' => 1, 'tapped' => 1, 'used' => 1, 'already_using' => 0]);
});

it('counts a person already using the feature before seeing the tip separately', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts']);

    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(10));
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(3));

    expect(adoptionStatusOf($tip, $ctx['storekeeper']))->toBe(TipAdoption::ALREADY_USING)
        ->and(adoptionFor($tip))->toBe(['saw' => 0, 'tapped' => 0, 'used' => 0, 'already_using' => 1]);
});

it('does not credit the tip with use after its adoption window', function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts', 'adoption_window_days' => 7]);

    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(20));
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::CLICKED, now()->subDays(20));
    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(5));

    expect(adoptionStatusOf($tip, $ctx['storekeeper']))->toBe(TipAdoption::TAPPED)
        ->and(adoptionFor($tip))->toBe(['saw' => 1, 'tapped' => 1, 'used' => 0, 'already_using' => 0]);
});

it('falls back to the platform default window when the tip sets none', function () {
    $ctx = engagementVendor();
    TipSetting::current()->update(['adoption_window_days' => 2]);
    $tip = makeTip(['feature_key' => 'customer_debts']);

    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(6));
    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDays(3));

    expect(adoptionStatusOf($tip, $ctx['storekeeper']))->toBe(TipAdoption::SAW);
});

it("counts staff on their own use, and owners on the business's", function () {
    $ctx = engagementVendor();
    $tip = makeTip(['feature_key' => 'customer_debts']);

    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(3));
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDays(3));
    // The owner never records a debt; the storekeeper does.
    recordTestDebt($ctx, $ctx['storekeeper'], now()->subDay());

    $rows = app(TipAdoption::class)->rows($tip);

    expect($rows->firstWhere('user_id', $ctx['owner']->id))
        ->status->toBe(TipAdoption::USED)
        ->business_wide->toBeTrue()
        ->and($rows->firstWhere('user_id', $ctx['storekeeper']->id))
        ->status->toBe(TipAdoption::USED)
        ->business_wide->toBeFalse();
});

it('keeps vendors apart: use at one vendor never adopts a tip at another', function () {
    $a = engagementVendor('A');
    $b = engagementVendor('B');
    $tip = makeTip(['feature_key' => 'customer_debts']);

    logTipEvent($tip, $b['owner'], $b['vendor'], TipEvent::SHOWN, now()->subDays(3));
    recordTestDebt($a, $a['owner'], now()->subDay());

    expect(adoptionStatusOf($tip, $b['owner']))->toBe(TipAdoption::SAW);
});

it('reports saw and tapped for a tip with no linked feature', function () {
    $ctx = engagementVendor();
    $tip = makeTip();

    logTipEvent($tip, $ctx['owner'], $ctx['vendor'], TipEvent::SHOWN, now()->subDay());
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::SHOWN, now()->subDay());
    logTipEvent($tip, $ctx['storekeeper'], $ctx['vendor'], TipEvent::CLICKED, now()->subDay());

    expect(adoptionFor($tip))->toBe(['saw' => 2, 'tapped' => 1, 'used' => 0, 'already_using' => 0]);
});
