<?php

use App\Filament\Pages\AffiliatePayoutBatch;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\AffiliateSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makePayoutAffiliate(float $availableBalance, bool $withBankDetails = true): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($withBankDetails) {
        $affiliate->update([
            'bank_name'      => 'GTBank',
            'account_number' => '0123456789',
            'account_name'   => $affiliate->user->name,
        ]);
    }

    if ($availableBalance != 0.0) {
        WalletTransaction::create([
            'affiliate_id' => $affiliate->id,
            'type'         => 'credit',
            'amount'       => $availableBalance,
            'description'  => 'Test seed credit',
        ]);
    }

    return $affiliate;
}

function actingAsSuperAdminForPayout(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('a super admin can access the payout batch page', function () {
    $this->actingAs(actingAsSuperAdminForPayout());

    expect(AffiliatePayoutBatch::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the payout batch page', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Payout Vendor']);

    $this->actingAs($owner);

    expect(AffiliatePayoutBatch::canAccess())->toBeFalse();
});

test('only affiliates meeting the minimum payout amount are listed', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $eligible   = makePayoutAffiliate(5000);
    $ineligible = makePayoutAffiliate(500);

    $this->actingAs(actingAsSuperAdminForPayout());

    Livewire::test(AffiliatePayoutBatch::class)
        ->assertSee($eligible->code)
        ->assertDontSee($ineligible->code);
});

test('running the payout batch on a selected affiliate creates a paid payout and a debit that zeroes the balance', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $admin     = actingAsSuperAdminForPayout();
    $affiliate = makePayoutAffiliate(5000);

    $this->actingAs($admin);

    Livewire::test(AffiliatePayoutBatch::class)
        ->callTableBulkAction('payOut', [$affiliate->id]);

    $payout = AffiliatePayout::where('affiliate_id', $affiliate->id)->first();

    expect($payout)->not->toBeNull()
        ->and($payout->status)->toBe('paid')
        ->and((float) $payout->amount)->toBe(5000.0)
        ->and($payout->paid_by)->toBe($admin->id)
        ->and(app(App\Services\Affiliate\WalletService::class)->availableBalance($affiliate->id))->toBe(0.0);

    $debit = WalletTransaction::where('affiliate_payout_id', $payout->id)->first();

    expect($debit)->not->toBeNull()
        ->and((float) $debit->amount)->toBe(-5000.0)
        ->and($debit->type)->toBe('debit');
});

test('paying out one affiliate does not touch another affiliate not selected in the batch', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $this->actingAs(actingAsSuperAdminForPayout());

    $paid    = makePayoutAffiliate(5000);
    $untouched = makePayoutAffiliate(3000);

    Livewire::test(AffiliatePayoutBatch::class)
        ->callTableBulkAction('payOut', [$paid->id]);

    expect(AffiliatePayout::where('affiliate_id', $untouched->id)->exists())->toBeFalse()
        ->and(app(App\Services\Affiliate\WalletService::class)->availableBalance($untouched->id))->toBe(3000.0);
});

test('the paid payout snapshots the affiliate\'s bank details', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $this->actingAs(actingAsSuperAdminForPayout());
    $affiliate = makePayoutAffiliate(5000);

    Livewire::test(AffiliatePayoutBatch::class)
        ->callTableBulkAction('payOut', [$affiliate->id]);

    $payout = AffiliatePayout::where('affiliate_id', $affiliate->id)->first();

    expect($payout->bank_name)->toBe('GTBank')
        ->and($payout->account_number)->toBe('0123456789')
        ->and($payout->account_name)->toBe($affiliate->user->name);
});

test('an eligible affiliate with no bank details is skipped, not paid', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $this->actingAs(actingAsSuperAdminForPayout());
    $affiliate = makePayoutAffiliate(5000, withBankDetails: false);

    Livewire::test(AffiliatePayoutBatch::class)
        ->callTableBulkAction('payOut', [$affiliate->id]);

    expect(AffiliatePayout::where('affiliate_id', $affiliate->id)->exists())->toBeFalse()
        ->and(app(App\Services\Affiliate\WalletService::class)->availableBalance($affiliate->id))->toBe(5000.0);
});

test('the navigation badge shows the count of affiliates eligible for payout', function () {
    AffiliateSetting::current()->update(['min_payout_amount' => 2000]);

    $this->actingAs(actingAsSuperAdminForPayout());

    makePayoutAffiliate(5000);
    makePayoutAffiliate(3000);
    makePayoutAffiliate(500);

    expect(AffiliatePayoutBatch::getNavigationBadge())->toBe('2');
});
