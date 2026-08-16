<?php

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeModelVendor(): Vendor
{
    $vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Model Vendor '.uniqid(),
    ]);

    // These tests are about Store's own behaviour — slugs, scopes, the
    // defaultStore relation — so they start from an empty vendor rather than
    // the "Main Store" Phase 2's VendorObserver now seeds. That auto-creation
    // has its own tests in ProductStoreStockBackfillTest.
    Store::where('vendor_id', $vendor->id)->delete();

    return $vendor->fresh();
}

test('a store belongs to its vendor and appears in the vendor\'s stores', function () {
    $vendor = makeModelVendor();
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store']);

    expect($store->vendor->id)->toBe($vendor->id)
        ->and($vendor->stores->pluck('id')->all())->toBe([$store->id]);
});

test('the slug is generated from the name, per vendor', function () {
    $vendorA = makeModelVendor();
    $vendorB = makeModelVendor();

    $a = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Main Store']);
    $b = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Main Store']);

    // Same slug under two vendors is allowed; a collision within one vendor is
    // what gets suffixed.
    $second = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Main Store']);

    expect($a->slug)->toBe('main-store')
        ->and($b->slug)->toBe('main-store')
        ->and($second->slug)->not->toBe('main-store');
});

test('defaultStore returns the default one and ignores the others', function () {
    $vendor = makeModelVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch', 'is_default' => false]);
    $default = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store', 'is_default' => true]);

    expect($vendor->fresh()->defaultStore->id)->toBe($default->id);
});

test('defaultStore is null when a vendor has no default store', function () {
    $vendor = makeModelVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch', 'is_default' => false]);

    expect($vendor->fresh()->defaultStore)->toBeNull();
});

test('storesForVendor returns only that vendor\'s stores for that user', function () {
    $vendorA = makeModelVendor();
    $vendorB = makeModelVendor();
    $user = User::factory()->create();

    $aMain = Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Main']);
    $aSide = Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Side']);
    $bMain = Store::create(['vendor_id' => $vendorB->id, 'name' => 'B Main']);
    $aUnlinked = Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Unlinked']);

    $user->stores()->attach([$aMain->id, $aSide->id, $bMain->id]);

    expect($user->storesForVendor($vendorA->id)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$aMain->id, $aSide->id])->sort()->values()->all())
        ->and($user->storesForVendor($vendorB->id)->pluck('id')->all())->toBe([$bMain->id])
        ->and($user->storesForVendor($vendorA->id)->pluck('id'))->not->toContain($aUnlinked->id);
});

test('storesForVendor is empty for a vendor the user has no store in', function () {
    $vendor = makeModelVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store']);

    expect(User::factory()->create()->storesForVendor($vendor->id))->toHaveCount(0);
});

test('the same user cannot be attached to one store twice', function () {
    $vendor = makeModelVendor();
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store']);
    $user = User::factory()->create();

    $store->users()->attach($user->id);

    expect(fn () => $store->users()->attach($user->id))->toThrow(QueryException::class);
    expect($store->fresh()->users)->toHaveCount(1);
});

test('the same slug cannot be written twice for one vendor', function () {
    $vendor = makeModelVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store', 'slug' => 'main-store']);

    expect(fn () => Store::query()->insert([
        'vendor_id' => $vendor->id,
        'name'      => 'Another',
        'slug'      => 'main-store',
    ]))->toThrow(QueryException::class);
});

test('deleting a vendor removes its stores and their memberships', function () {
    $vendor = makeModelVendor();
    $store = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Store']);
    $store->users()->attach(User::factory()->create()->id);

    $vendor->delete();

    expect(Store::find($store->id))->toBeNull()
        ->and(DB::table('store_user')->where('store_id', $store->id)->count())->toBe(0);
});

test('store scopes filter by vendor and by active', function () {
    $vendorA = makeModelVendor();
    $vendorB = makeModelVendor();

    $active = Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Main']);
    Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Closed', 'is_active' => false]);
    Store::create(['vendor_id' => $vendorB->id, 'name' => 'B Main']);

    expect(Store::forVendor($vendorA->id)->count())->toBe(2)
        ->and(Store::forVendor($vendorA->id)->active()->pluck('id')->all())->toBe([$active->id]);
});
