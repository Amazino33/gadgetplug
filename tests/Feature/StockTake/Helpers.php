<?php

// Shared fixtures for the Stock Take tests. In a helper file because Pest
// loads every test file into one global function namespace (see
// tests/Feature/Cash/Helpers.php).

use App\Models\Aisle;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\StockTake;
use App\Models\StockTakeTask;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;

/**
 * A vendor with its default branch, its default roles, an owner, a store
 * admin and two storekeepers — all assigned to the branch.
 */
function stockTakeContext(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Stock Take Vendor '.uniqid(), 'stock_take_enabled' => true]);
    $store  = $vendor->defaultStore;

    (new VendorPermissionsSeeder())->run();
    VendorRoles::seedFor($vendor);

    $staff = fn (string $role) => tap(User::factory()->create(), function (User $user) use ($vendor, $store, $role) {
        $vendor->users()->syncWithoutDetaching([$user->id]);
        $user->stores()->attach($store->id);
        setPermissionsTeamId($vendor->id);
        $user->assignRole($role);
    });

    return [
        'vendor'  => $vendor,
        'store'   => $store,
        'owner'   => $owner,
        'admin'   => $staff('store_admin'),
        'keeperA' => $staff('storekeeper'),
        'keeperB' => $staff('storekeeper'),
    ];
}

function stAisle(array $ctx, string $name, ?Store $store = null): Aisle
{
    $store ??= $ctx['store'];

    return Aisle::create([
        'vendor_id' => $store->vendor_id,
        'store_id'  => $store->id,
        'name'      => $name,
    ]);
}

function stProduct(array $ctx, string $name = 'Peak Milk 400g', int|float $onShelf = 50): Product
{
    $product = Product::create([
        'vendor_id'      => $ctx['vendor']->id,
        'store_id'       => $ctx['store']->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name.' '.uniqid(),
        'price'          => 1500,
        'cost_price'     => 1000,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
        ['quantity' => $onShelf, 'reserved' => 0],
    );

    return $product;
}

function stStockTake(array $ctx, array $over = []): StockTake
{
    return StockTake::create(array_merge([
        'vendor_id'  => $ctx['vendor']->id,
        'store_id'   => $ctx['store']->id,
        'name'       => 'November Full Count',
        'start_date' => '2026-11-24',
        'end_date'   => '2026-11-30',
        'created_by' => $ctx['admin']->id,
    ], $over));
}

function stTask(array $ctx, StockTake $stockTake, User $to, string $type = StockTakeTask::TYPE_COUNT, array $over = []): StockTakeTask
{
    return StockTakeTask::create(array_merge([
        'vendor_id'     => $stockTake->vendor_id,
        'store_id'      => $stockTake->store_id,
        'stock_take_id' => $stockTake->id,
        'type'          => $type,
        'assigned_to'   => $to->id,
        'due_date'      => '2026-11-25',
        'created_by'    => $ctx['admin']->id,
    ], $over));
}
