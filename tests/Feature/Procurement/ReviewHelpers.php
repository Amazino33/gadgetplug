<?php

// Shared fixtures for the two-party procurement review tests.
//
// In a helper file for the same reason the cash ones are: Pest loads every
// test file into one global function namespace, so a helper declared in a
// sibling test only exists when that sibling happens to load first, and
// running a file on its own then fails on an undefined function.

use App\Models\Category;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;

/**
 * A branch with the two people a delivery needs kept apart: whoever records
 * what arrived, and somebody else who signs it into stock.
 */
function reviewContext(): array
{
    $vendor = Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Review Vendor '.uniqid(),
    ]);

    $store = $vendor->defaultStore;
    $owner = User::find($vendor->user_id);

    // Roles only carry permissions that already exist as rows, so the
    // permission seeder has to run first.
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    App\Services\VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);

    // Records deliveries.
    //
    // inventory_manager, not storekeeper, because recording a procurement
    // needs manage_procurement — the wizard and the resource both gate on it,
    // since building an order means seeing every product's cost price. The
    // storekeeper role deliberately lacks it, so it can never be the recorder
    // and a fixture using it would be testing a person who cannot exist.
    //
    // That means the recorder here DOES hold approve_procurement, which makes
    // this the stronger fixture anyway: they are refused because they recorded
    // this delivery, not because they lack the rights to receive one.
    $keeper = User::factory()->create();
    $keeper->stores()->attach($store->id);
    $vendor->users()->syncWithoutDetaching([$keeper->id]);
    setPermissionsTeamId($vendor->id);
    $keeper->assignRole('inventory_manager');

    // Checks them in.
    $checker = User::factory()->create();
    $checker->stores()->attach($store->id);
    $vendor->users()->syncWithoutDetaching([$checker->id]);
    setPermissionsTeamId($vendor->id);
    $checker->assignRole('inventory_manager');

    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    return compact('vendor', 'store', 'owner', 'keeper', 'checker', 'supplier');
}

/** A third person who may approve, for the "strictly two people" rule. */
function reviewOutsider(array $ctx): User
{
    $user = User::factory()->create();
    $user->stores()->attach($ctx['store']->id);
    $ctx['vendor']->users()->syncWithoutDetaching([$user->id]);
    setPermissionsTeamId($ctx['vendor']->id);
    $user->assignRole('inventory_manager');

    return $user;
}

function reviewProduct(array $ctx, string $name = 'Oraimo Earbuds', ?Store $home = null): Product
{
    $store = $home ?? $ctx['store'];

    $product = Product::create([
        'vendor_id'      => $ctx['vendor']->id,
        'store_id'       => $store->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name,
        'price'          => 10000,
        'cost_price'     => 5000,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => 0, 'reserved' => 0],
    );

    return $product->fresh();
}

/**
 * A delivery as the wizard writes one: recorded by one person, waiting on
 * somebody else, and holding no stock yet.
 *
 * @param  array<int, array{0: Product, 1: int, 2: float}>  $items
 */
function reviewDelivery(array $ctx, array $items, ?Store $destination = null): Procurement
{
    $total = 0;

    $procurement = Procurement::create([
        'vendor_id'      => $ctx['vendor']->id,
        'store_id'       => ($destination ?? $ctx['store'])->id,
        'supplier_id'    => $ctx['supplier']->id,
        'created_by'     => $ctx['keeper']->id,
        'status'         => Procurement::STATUS_PENDING,
        'amount_paid'    => 0,
        'payment_status' => 'credit',
        'payment_method' => 'cash',
    ]);

    foreach ($items as [$product, $quantity, $unitCost]) {
        $procurement->items()->create([
            'product_id'    => $product->id,
            'quantity'      => $quantity,
            'unit_cost'     => $unitCost,
            'selling_price' => $unitCost * 2,
        ]);

        $total += $quantity * $unitCost;
    }

    $procurement->updateQuietly(['total_cost' => $total]);

    return $procurement->fresh();
}

function reviewStock(Product $product, Store $store): int
{
    return (int) ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity');
}
