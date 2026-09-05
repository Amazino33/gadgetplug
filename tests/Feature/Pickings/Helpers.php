<?php

// Shared fixtures for the picking tests.
//
// In a helper file rather than in one of the test files because Pest loads every
// test file into one global function namespace: helpers declared in a sibling
// test only exist when that sibling happens to be loaded too, so running a file
// on its own then fails. require_once here guarantees one definition however the
// suite is invoked.

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Category;
use App\Models\Picker;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Str;

function pickingVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Picking Vendor '.uniqid(),
    ]);
}

function pickingPicker(Vendor $vendor, string $name = 'Musa Bala'): Picker
{
    return Picker::create([
        'vendor_id' => $vendor->id,
        'name'      => $name,
        'shop'      => 'Shop 12, Plaza',
    ]);
}

/** Stocked through a real movement so the cost layers exist, as they would in life. */
function pickingProduct(Vendor $vendor, Store $store, int $quantity, float $cost = 600, float $price = 1000): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Picking Cat'])->id,
        'name'           => 'Picked Widget '.Str::random(4),
        'price'          => $price,
        'cost_price'     => $cost,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    if ($quantity > 0) {
        app(AdjustStockAction::class)->execute(
            productId: $product->id,
            quantityChanged: $quantity,
            transactionType: 'restock',
            store: $store->id,
        );
    }

    return $product->fresh();
}

function shelfQuantity(Product $product, Store $store): int
{
    return (int) ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $store->id)
        ->value('quantity');
}
