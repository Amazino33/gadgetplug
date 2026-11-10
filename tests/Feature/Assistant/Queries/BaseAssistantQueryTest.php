<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\SlowProductsQuery;
use App\Exceptions\Assistant\AggregateNotSupported;
use App\Exceptions\Assistant\DateRangeTooLarge;
use App\Exceptions\Assistant\InvalidScope;
use App\Exceptions\Assistant\UnauthorizedStoreAccess;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('missing scope throws InvalidScope exception', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute([], now()->subDays(7), now()))
        ->toThrow(InvalidScope::class);
});

test('providing both store_id and store_ids throws InvalidScope exception', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute([
        'store_id' => 1,
        'store_ids' => [1, 2],
    ], now()->subDays(7), now()))->toThrow(InvalidScope::class);
});

test('store not owned by user throws UnauthorizedStoreAccess', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $vendorB = Vendor::create(['user_id' => $userB->id, 'name' => 'Shop B']);
    $storeB = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Branch B']);

    $this->actingAs($userA);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute(['store_id' => $storeB->id], now()->subDays(7), now()))
        ->toThrow(UnauthorizedStoreAccess::class);
});

test('from date later than to date throws InvalidScope', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Shop A']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch A']);

    $this->actingAs($user);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute(['store_id' => $store->id], now(), now()->subDays(5)))
        ->toThrow(InvalidScope::class);
});

test('range greater than 365 days throws DateRangeTooLarge', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Shop A']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch A']);

    $this->actingAs($user);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute(['store_id' => $store->id], now()->subDays(370), now()))
        ->toThrow(DateRangeTooLarge::class);
});

test('multi-store scope on non-aggregate query throws AggregateNotSupported', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Shop A']);
    $store1 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch 1']);
    $store2 = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch 2']);

    $this->actingAs($user);

    // SlowProductsQuery does NOT support aggregate (LowStockQuery does since Phase 2)
    $query = new SlowProductsQuery;
    expect(fn () => $query->execute([
        'store_ids' => [$store1->id, $store2->id],
    ], now()->subDays(7), now()))->toThrow(AggregateNotSupported::class);
});

test('ownership isolation prevents Vendor A from reading Vendor B data', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $vendorA = Vendor::create(['user_id' => $userA->id, 'name' => 'Shop A']);
    $storeA = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Branch A']);

    $vendorB = Vendor::create(['user_id' => $userB->id, 'name' => 'Shop B']);
    $storeB = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Branch B']);

    $this->actingAs($userA);

    $query = new SalesSummaryQuery;

    // Querying own store succeeds
    $resultA = $query->execute(['store_id' => $storeA->id], now()->subDays(7), now());
    expect($resultA)->toBeArray();

    // Querying Vendor B's store strictly rejected
    expect(fn () => $query->execute(['store_id' => $storeB->id], now()->subDays(7), now()))
        ->toThrow(UnauthorizedStoreAccess::class);
});

test('store-staff assigned user who does not own vendor cannot execute queries (requires vendor-owner)', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Owner Shop']);
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Owner Branch']);

    // Assign staff via store_user pivot
    $store->users()->attach($staff->id);

    $this->actingAs($staff);

    $query = new SalesSummaryQuery;
    expect(fn () => $query->execute(['store_id' => $store->id], now()->subDays(7), now()))
        ->toThrow(UnauthorizedStoreAccess::class);

    // Vendor owner querying their store succeeds
    $this->actingAs($owner);
    $res = $query->execute(['store_id' => $store->id], now()->subDays(7), now());
    expect($res)->toBeArray();
});
