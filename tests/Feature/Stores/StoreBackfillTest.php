<?php

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The backfill migration runs once against an empty database during
// RefreshDatabase, so re-running it here is the only way to exercise it against
// real vendors. Migration files return the anonymous class instance, and
// require (not require_once) re-evaluates on every call, so each invocation
// gets its own object.
function runStoreBackfill(): void
{
    $migration = require database_path('migrations/2026_08_15_100002_backfill_default_stores_and_store_memberships.php');

    $migration->up();
}

function makeStoreVendor(string $name = null): Vendor
{
    $owner = User::factory()->create();

    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name'    => $name ?? 'Store Vendor '.uniqid(),
    ]);

    // Phase 2's VendorObserver now seeds a default store on creation, which is
    // precisely the state this backfill exists to produce. Clearing it puts the
    // vendor back in the pre-Phase-1 shape these tests are about — a vendor with
    // stock and members but no store. The observer's own behaviour is covered
    // in ProductStoreStockBackfillTest.
    Store::where('vendor_id', $vendor->id)->delete();

    return $vendor->fresh();
}

test('every vendor gets exactly one default store', function () {
    $vendorA = makeStoreVendor();
    $vendorB = makeStoreVendor();

    runStoreBackfill();

    foreach ([$vendorA, $vendorB] as $vendor) {
        $defaults = Store::where('vendor_id', $vendor->id)->where('is_default', true)->get();

        expect($defaults)->toHaveCount(1)
            ->and($defaults->first()->name)->toBe('Main Store')
            ->and($defaults->first()->is_active)->toBeTrue()
            ->and($defaults->first()->slug)->toBe('main-store');
    }
});

test('two vendors may each hold the same store slug', function () {
    makeStoreVendor();
    makeStoreVendor();

    runStoreBackfill();

    expect(Store::where('slug', 'main-store')->count())->toBe(2);
});

test('every vendor member is linked to that vendor\'s default store', function () {
    $vendor = makeStoreVendor();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $vendor->users()->attach([$memberA->id, $memberB->id]);

    runStoreBackfill();

    $store = $vendor->fresh()->defaultStore;

    expect($store->users->pluck('id')->sort()->values()->all())
        ->toBe(collect([$memberA->id, $memberB->id])->sort()->values()->all());
});

test('a member of one vendor is not linked to another vendor\'s store', function () {
    $vendorA = makeStoreVendor();
    $vendorB = makeStoreVendor();
    $member = User::factory()->create();
    $vendorA->users()->attach($member->id);

    runStoreBackfill();

    expect($vendorB->fresh()->defaultStore->users)->toHaveCount(0);
});

test('the backfill is idempotent — running it again creates nothing new', function () {
    $vendor = makeStoreVendor();
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);

    runStoreBackfill();
    runStoreBackfill();
    runStoreBackfill();

    expect(Store::where('vendor_id', $vendor->id)->count())->toBe(1)
        ->and(DB::table('store_user')->count())->toBe(1);
});

test('the backfill leaves an existing default store and its curated members alone', function () {
    $vendor = makeStoreVendor();
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);

    $existing = Store::create([
        'vendor_id'  => $vendor->id,
        'name'       => 'Uyo Branch',
        'slug'       => 'uyo-branch',
        'is_default' => true,
        'is_active'  => true,
    ]);

    runStoreBackfill();

    expect(Store::where('vendor_id', $vendor->id)->count())->toBe(1)
        ->and($vendor->fresh()->defaultStore->id)->toBe($existing->id)
        // The member still had to reach it — an existing store is not a reason
        // to skip granting access.
        ->and($existing->fresh()->users->pluck('id')->all())->toBe([$member->id]);
});

test('a vendor with no members gets a store and no memberships', function () {
    $vendor = makeStoreVendor();

    runStoreBackfill();

    expect($vendor->fresh()->defaultStore)->not->toBeNull()
        ->and(DB::table('store_user')->count())->toBe(0);
});

test('the backfill fails loudly when a vendor ends up with two default stores', function () {
    $vendor = makeStoreVendor();

    Store::create(['vendor_id' => $vendor->id, 'name' => 'One', 'slug' => 'one', 'is_default' => true]);
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Two', 'slug' => 'two', 'is_default' => true]);

    expect(fn () => runStoreBackfill())
        ->toThrow(RuntimeException::class, 'without exactly one default store');
});
