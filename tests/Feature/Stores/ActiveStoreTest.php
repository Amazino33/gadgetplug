<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activeStoreVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Active Store Vendor '.uniqid(),
    ]);
}

function memberOf(Vendor $vendor, array $storeIds = []): User
{
    $user = User::factory()->create();
    $vendor->users()->attach($user->id);

    if ($storeIds !== []) {
        $user->stores()->attach($storeIds);
    }

    return $user;
}

// ─── Accessible stores ──────────────────────────────────────────────

test('the owner sees every store the vendor has, without any store_user row', function () {
    $vendor = activeStoreVendor();
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);
    $third  = Store::create(['vendor_id' => $vendor->id, 'name' => 'Third Branch']);

    $accessible = ActiveStore::accessibleFor($vendor, $vendor->user);

    expect($accessible->pluck('id')->sort()->values()->all())
        ->toBe(collect([$vendor->defaultStore->id, $second->id, $third->id])->sort()->values()->all())
        // Owner access runs through vendors.user_id, never through the pivot.
        ->and(DB::table('store_user')->count())->toBe(0);
});

test('a member sees only the stores they are assigned to', function () {
    $vendor = activeStoreVendor();
    $assigned = Store::create(['vendor_id' => $vendor->id, 'name' => 'Assigned Branch']);
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Other Branch']);

    $member = memberOf($vendor, [$assigned->id]);

    expect(ActiveStore::accessibleFor($vendor, $member)->pluck('id')->all())->toBe([$assigned->id]);
});

test('a member assigned to nothing sees no stores', function () {
    $vendor = activeStoreVendor();
    $member = memberOf($vendor);

    expect(ActiveStore::accessibleFor($vendor, $member))->toHaveCount(0)
        ->and(ActiveStore::get($vendor, $member))->toBeNull();
});

test('another vendor stores never leak into the accessible set', function () {
    $vendorA = activeStoreVendor();
    $vendorB = activeStoreVendor();
    $foreign = $vendorB->defaultStore;

    $member = memberOf($vendorA, [$vendorA->defaultStore->id]);
    // Assigned to a store belonging to a vendor they are not a member of.
    $member->stores()->attach($foreign->id);

    expect(ActiveStore::accessibleFor($vendorA, $member)->pluck('id')->all())
        ->toBe([$vendorA->defaultStore->id]);
});

// ─── The guard ──────────────────────────────────────────────────────

test('a member cannot select a store they are not assigned to', function () {
    $vendor = activeStoreVendor();
    $forbidden = Store::create(['vendor_id' => $vendor->id, 'name' => 'Forbidden Branch']);
    $member = memberOf($vendor, [$vendor->defaultStore->id]);

    expect(ActiveStore::set($vendor, $member, $forbidden->id))->toBeFalse()
        ->and(ActiveStore::canAccess($vendor, $member, $forbidden->id))->toBeFalse()
        ->and(ActiveStore::get($vendor, $member)->id)->toBe($vendor->defaultStore->id);
});

test('nobody can select a store belonging to another vendor', function () {
    $vendorA = activeStoreVendor();
    $vendorB = activeStoreVendor();

    // Even the owner of vendor A cannot reach vendor B's store.
    expect(ActiveStore::set($vendorA, $vendorA->user, $vendorB->defaultStore->id))->toBeFalse();
});

test('an owner can select any of their own stores', function () {
    $vendor = activeStoreVendor();
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    expect(ActiveStore::set($vendor, $vendor->user, $second->id))->toBeTrue()
        ->and(ActiveStore::get($vendor, $vendor->user)->id)->toBe($second->id);
});

test('a revoked assignment drops the user out of the store on the next read', function () {
    $vendor = activeStoreVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Temp Branch']);
    $member = memberOf($vendor, [$vendor->defaultStore->id, $branch->id]);

    ActiveStore::set($vendor, $member, $branch->id);
    expect(ActiveStore::get($vendor, $member)->id)->toBe($branch->id);

    $member->stores()->detach($branch->id);

    // The session still names the branch; the read re-checks and falls back.
    expect(ActiveStore::get($vendor, $member)->id)->toBe($vendor->defaultStore->id);
});

// ─── Resolution and session isolation ───────────────────────────────

test('resolution with nothing set picks the default store', function () {
    $vendor = activeStoreVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Aaa Branch']);

    // Alphabetically first, but not the default — the default wins.
    expect(ActiveStore::get($vendor, $vendor->user)->is_default)->toBeTrue();
});

test('a member with no default store falls back to their first accessible one', function () {
    $vendor = activeStoreVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Only Assigned']);
    $member = memberOf($vendor, [$branch->id]);

    expect(ActiveStore::get($vendor, $member)->id)->toBe($branch->id);
});

test('the active store is remembered per vendor and does not leak across them', function () {
    $vendorA = activeStoreVendor();
    $vendorB = activeStoreVendor();
    $branchA = Store::create(['vendor_id' => $vendorA->id, 'name' => 'A Branch']);
    $branchB = Store::create(['vendor_id' => $vendorB->id, 'name' => 'B Branch']);

    $user = User::factory()->create();
    $vendorA->users()->attach($user->id);
    $vendorB->users()->attach($user->id);
    $user->stores()->attach([$branchA->id, $branchB->id]);

    ActiveStore::set($vendorA, $user, $branchA->id);
    ActiveStore::set($vendorB, $user, $branchB->id);

    // Each vendor keeps its own selection in its own session key.
    expect(ActiveStore::get($vendorA, $user)->id)->toBe($branchA->id)
        ->and(ActiveStore::get($vendorB, $user)->id)->toBe($branchB->id);
});

// ─── The storekeeper case, folded into the same rule ────────────────

test('a storekeeper with exactly one store lands straight in it', function () {
    $vendor = activeStoreVendor();
    Store::create(['vendor_id' => $vendor->id, 'name' => 'Unassigned Branch']);
    $keeper = memberOf($vendor, [$vendor->defaultStore->id]);

    $accessible = ActiveStore::accessibleFor($vendor, $keeper);

    expect($accessible)->toHaveCount(1)
        ->and(ActiveStore::get($vendor, $keeper)->id)->toBe($vendor->defaultStore->id);
});

test('a storekeeper assigned to several stores gets the choice', function () {
    $vendor = activeStoreVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);
    $keeper = memberOf($vendor, [$vendor->defaultStore->id, $branch->id]);

    expect(ActiveStore::accessibleFor($vendor, $keeper))->toHaveCount(2);
});

// ─── Per-store stock metrics behind the cards ───────────────────────

test('store metrics count only that store rows', function () {
    $vendor = activeStoreVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Metric Product', 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'low_stock_threshold' => 5, 'status' => 'published',
    ]);

    ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => 10]);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 2]);

    $metrics = App\Services\Inventory\StoreStockMetrics::forStores([$vendor->defaultStore->id, $branch->id]);

    expect($metrics[$vendor->defaultStore->id]->retail_value)->toBe(10000.0)
        ->and($metrics[$vendor->defaultStore->id]->cost_value)->toBe(4000.0)
        ->and($metrics[$vendor->defaultStore->id]->low_stock_count)->toBe(0)
        ->and($metrics[$branch->id]->retail_value)->toBe(2000.0)
        // 2 available, under the threshold of 5.
        ->and($metrics[$branch->id]->low_stock_count)->toBe(1)
        ->and($metrics[$branch->id]->product_count)->toBe(1);
});
