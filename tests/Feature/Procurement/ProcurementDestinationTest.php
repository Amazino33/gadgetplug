<?php

use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function poVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'PO Vendor '.uniqid(),
    ]);
}

function poProduct(Vendor $vendor, string $name, Store $home, int $quantity = 0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name,
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    if ($quantity > 0) {
        ProductStoreStock::where('product_id', $product->id)
            ->where('store_id', $home->id)
            ->first()
            ->update(['quantity' => $quantity]);
    }

    return $product->fresh();
}

function poFor(Vendor $vendor, ?Store $destination, array $items): Procurement
{
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    $procurement = Procurement::create([
        'vendor_id'   => $vendor->id,
        'store_id'    => $destination?->id,
        'supplier_id' => $supplier->id,
        'reference'   => 'PO-'.uniqid(),
        'status'      => 'pending',
        'created_by'  => $vendor->user_id,
    ]);

    foreach ($items as [$product, $quantity]) {
        $procurement->items()->create([
            'product_id'    => $product->id,
            'quantity'      => $quantity,
            'unit_cost'     => 500,
            'selling_price' => 1000,
        ]);
    }

    return $procurement;
}

function quantityAt(Product $product, Store $store): int
{
    return (int) ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity');
}

test('goods land in the branch the order names, not the one the approver is in', function () {
    $vendor = poVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);
    $product = poProduct($vendor, 'Homed At Branch', $branch);
    $procurement = poFor($vendor, $branch, [[$product, 5]]);

    $this->actingAs(User::find($vendor->user_id));

    // The approver is standing in the default store. The order still lands
    // where the order says.
    app(ApproveProcurementAction::class)->execute($procurement, $vendor->defaultStore->id);

    expect(quantityAt($product, $branch))->toBe(5)
        ->and(quantityAt($product, $vendor->defaultStore))->toBe(0)
        ->and(InventoryLedger::where('product_id', $product->id)->value('store_id'))->toBe($branch->id);
});

test('a product holding stock nowhere is re-homed to the branch receiving it', function () {
    $vendor = poVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    // Homed at the default store but empty — nothing ties it to that branch,
    // so procurement is how a newly opened branch gets its first stock.
    $product = poProduct($vendor, 'Never Stocked', $vendor->defaultStore, 0);
    $procurement = poFor($vendor, $branch, [[$product, 10]]);

    $this->actingAs(User::find($vendor->user_id));
    app(ApproveProcurementAction::class)->execute($procurement);

    expect($product->fresh()->store_id)->toBe($branch->id)
        ->and(quantityAt($product, $branch))->toBe(10);
});

test('an item stocked at another branch is refused, and the whole order rolls back', function () {
    $vendor = poVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $stocked  = poProduct($vendor, 'JBL Speaker', $vendor->defaultStore, 6);
    $received = poProduct($vendor, 'Fine To Receive', $branch, 0);
    $procurement = poFor($vendor, $branch, [[$received, 4], [$stocked, 3]]);

    $this->actingAs(User::find($vendor->user_id));

    expect(fn () => app(ApproveProcurementAction::class)->execute($procurement))
        ->toThrow(RuntimeException::class, 'JBL Speaker');

    // Received whole or not at all: the item that could have landed did not,
    // the order is still pending, and nothing was re-homed.
    expect(quantityAt($received, $branch))->toBe(0)
        ->and(quantityAt($stocked, $vendor->defaultStore))->toBe(6)
        ->and($stocked->fresh()->store_id)->toBe($vendor->defaultStore->id)
        ->and($procurement->fresh()->status)->toBe('pending')
        ->and(InventoryLedger::where('reference', $procurement->reference)->count())->toBe(0);
});

test('an order raised before destinations existed still lands in the default store', function () {
    $vendor = poVendor();
    $product = poProduct($vendor, 'Legacy Order', $vendor->defaultStore, 2);
    $procurement = poFor($vendor, null, [[$product, 3]]);

    $this->actingAs(User::find($vendor->user_id));
    app(ApproveProcurementAction::class)->execute($procurement);

    expect(quantityAt($product, $vendor->defaultStore))->toBe(5)
        ->and($procurement->fresh()->status)->toBe('approved');
});
