<?php

use App\Models\FinancialAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinancialAccounts;
use Database\Seeders\FinancialAccountsBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a new vendor is seeded with exactly one bank and one cash account, both at zero', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Accounts Test Store']);

    $accounts = FinancialAccount::where('vendor_id', $vendor->id)->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts->pluck('type')->sort()->values()->all())->toBe(['bank', 'cash'])
        ->and($accounts->every(fn (FinancialAccount $a) => (float) $a->opening_balance === 0.0))->toBeTrue()
        ->and($accounts->every(fn (FinancialAccount $a) => $a->is_active === true))->toBeTrue();
});

test('seeding twice does not duplicate accounts', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Idempotent Store']);

    FinancialAccounts::seedFor($vendor);
    FinancialAccounts::seedFor($vendor);

    expect(FinancialAccount::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('the backfill seeder creates accounts for a vendor that predates this feature', function () {
    // Simulates a vendor that existed before FinancialAccounts::seedFor() was
    // wired into VendorObserver — detach its accounts to reproduce that state.
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Legacy Store']);
    FinancialAccount::where('vendor_id', $vendor->id)->delete();

    expect(FinancialAccount::where('vendor_id', $vendor->id)->count())->toBe(0);

    (new FinancialAccountsBackfillSeeder())->run();

    expect(FinancialAccount::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('the backfill seeder is safe to re-run and does not duplicate existing accounts', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Already Seeded Store']);

    (new FinancialAccountsBackfillSeeder())->run();
    (new FinancialAccountsBackfillSeeder())->run();

    expect(FinancialAccount::where('vendor_id', $vendor->id)->count())->toBe(2);
});

test('each vendor only gets its own two accounts, never another vendor\'s', function () {
    $ownerA = User::factory()->create();
    $vendorA = Vendor::create(['user_id' => $ownerA->id, 'name' => 'Store A']);

    $ownerB = User::factory()->create();
    $vendorB = Vendor::create(['user_id' => $ownerB->id, 'name' => 'Store B']);

    expect(FinancialAccount::where('vendor_id', $vendorA->id)->count())->toBe(2)
        ->and(FinancialAccount::where('vendor_id', $vendorB->id)->count())->toBe(2)
        ->and(FinancialAccount::count())->toBe(4);
});

test('balance() reflects the opening balance until the ledger exists', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Balance Test Store']);

    $bank = FinancialAccount::where('vendor_id', $vendor->id)->where('type', 'bank')->first();
    $bank->update(['opening_balance' => 15000]);

    expect($bank->fresh()->balance())->toBe(15000.0);
});

test('is_active is cast to a real boolean, not a raw db value', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Cast Test Store']);

    $account = FinancialAccount::where('vendor_id', $vendor->id)->first();

    expect($account->is_active)->toBeBool()
        ->and((float) $account->opening_balance)->toBe(0.0);
});

test('the vendor relationship resolves correctly', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Relation Test Store']);

    $account = FinancialAccount::where('vendor_id', $vendor->id)->first();

    expect($account->vendor->id)->toBe($vendor->id);
});
