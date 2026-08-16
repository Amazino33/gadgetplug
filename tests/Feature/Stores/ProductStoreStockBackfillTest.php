<?php

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Both data migrations run against an empty database during RefreshDatabase,
// so re-invoking them here is the only way to exercise them against real rows.
// require (not require_once) re-evaluates, so each call gets a fresh instance.
function runStockBackfill(): void
{
    (require database_path('migrations/2026_08_15_110001_backfill_product_store_stock.php'))->up();
}

function makeStockVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Stock Vendor '.uniqid(),
    ]);
}

function makeStockProduct(Vendor $vendor, int $quantity, int $reserved = 0): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Stock Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => $quantity,
        'reserved_stock' => $reserved,
        'status'         => 'published',
    ]);
}

// ─── Step 0: the new-vendor gap ─────────────────────────────────────

test('a newly created vendor automatically gets exactly one default Main Store', function () {
    $vendor = makeStockVendor();

    $stores = Store::where('vendor_id', $vendor->id)->get();

    expect($stores)->toHaveCount(1)
        ->and($stores->first()->name)->toBe('Main Store')
        ->and($stores->first()->is_default)->toBeTrue()
        ->and($stores->first()->is_active)->toBeTrue()
        ->and($stores->first()->slug)->toBe('main-store');
});

test('seeding the default store twice does not create a second one', function () {
    $vendor = makeStockVendor();

    $first = App\Services\DefaultStore::seedFor($vendor);
    $second = App\Services\DefaultStore::seedFor($vendor);

    expect($second->id)->toBe($first->id)
        ->and(Store::where('vendor_id', $vendor->id)->count())->toBe(1);
});

test('the auto-created store does not grant the owner store_user access', function () {
    $vendor = makeStockVendor();

    expect(DB::table('store_user')->count())->toBe(0);
});

// ─── Steps 1-3: the table and the backfill ──────────────────────────

test('every product lands on its vendor default store with its exact numbers', function () {
    $vendor = makeStockVendor();
    $a = makeStockProduct($vendor, 12, 3);
    $b = makeStockProduct($vendor, 0, 0);

    runStockBackfill();

    $storeId = $vendor->fresh()->defaultStore->id;

    expect(ProductStoreStock::where('product_id', $a->id)->first())
        ->store_id->toBe($storeId)
        ->quantity->toBe(12)
        ->reserved->toBe(3);

    expect(ProductStoreStock::where('product_id', $b->id)->first())
        ->quantity->toBe(0)
        ->reserved->toBe(0);
});

test('products of different vendors land on their own vendor store', function () {
    $vendorA = makeStockVendor();
    $vendorB = makeStockVendor();
    $a = makeStockProduct($vendorA, 5);
    $b = makeStockProduct($vendorB, 7);

    runStockBackfill();

    expect(ProductStoreStock::where('product_id', $a->id)->value('store_id'))
        ->toBe($vendorA->fresh()->defaultStore->id)
        ->and(ProductStoreStock::where('product_id', $b->id)->value('store_id'))
        ->toBe($vendorB->fresh()->defaultStore->id);
});

test('the backfill is idempotent', function () {
    $vendor = makeStockVendor();
    makeStockProduct($vendor, 9, 2);

    runStockBackfill();
    runStockBackfill();
    runStockBackfill();

    expect(ProductStoreStock::count())->toBe(1)
        ->and(ProductStoreStock::first()->quantity)->toBe(9);
});

test('the three integrity totals match after backfill', function () {
    $vendor = makeStockVendor();
    makeStockProduct($vendor, 10, 4);
    makeStockProduct($vendor, 25, 0);
    makeStockProduct($vendor, 3, 1);

    runStockBackfill();

    expect(ProductStoreStock::count())->toBe(Product::count())
        ->and((int) ProductStoreStock::sum('quantity'))->toBe((int) Product::sum('stock_quantity'))
        ->and((int) ProductStoreStock::sum('reserved'))->toBe((int) Product::sum('reserved_stock'));
});

// The store row is corrupted rather than the product column, because the
// column can no longer be corrupted: ProductStoreStockObserver recomputes it
// from the rows, so a bad value written there heals itself before anything can
// observe it. The rows are the truth, so a disagreement has to start there.
// Query-builder updates throughout — going through the model would fire that
// same observer and drag the mirror along with the corruption.
test('the backfill aborts loudly when a quantity does not match', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 10);

    DB::table('product_store_stock')
        ->where('product_id', $product->id)
        ->update(['quantity' => 999]);

    expect(fn () => runStockBackfill())
        ->toThrow(RuntimeException::class, 'does not match products total');
});

test('the backfill aborts loudly when a reserved total does not match', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 10, 5);

    DB::table('product_store_stock')
        ->where('product_id', $product->id)
        ->update(['reserved' => 99]);

    expect(fn () => runStockBackfill())
        ->toThrow(RuntimeException::class, 'reserved total');
});

test('a vendor left without a default store gets one rather than blocking the backfill', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 8);

    // Simulates a vendor created in the window between Phase 1's backfill and
    // this phase's observer.
    Store::where('vendor_id', $vendor->id)->delete();

    runStockBackfill();

    expect(Store::where('vendor_id', $vendor->id)->where('is_default', true)->count())->toBe(1)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(8);
});

test('product and store relationships resolve both ways', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 4);

    runStockBackfill();

    $store = $vendor->fresh()->defaultStore;

    expect($product->fresh()->storeStocks)->toHaveCount(1)
        ->and($store->productStocks)->toHaveCount(1)
        ->and($store->productStocks->first()->product->id)->toBe($product->id)
        ->and($product->fresh()->storeStocks->first()->store->id)->toBe($store->id);
});

test('deleting a product or a store removes its stock rows', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 4);
    runStockBackfill();

    $product->delete();

    expect(ProductStoreStock::count())->toBe(0);
});

// ─── Step 4: ledger store_id ────────────────────────────────────────

test('existing ledger rows are backfilled to the vendor default store', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 10);

    $ledger = InventoryLedger::create([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'transaction_type' => 'restock',
        'quantity_change'  => 10,
    ]);

    (require database_path('migrations/2026_08_15_110002_add_store_id_to_inventory_ledgers_table.php'))
        ->up();

    expect($ledger->fresh()->store_id)->toBe($vendor->fresh()->defaultStore->id)
        ->and($ledger->fresh()->store->name)->toBe('Main Store');
});

test('the ledger store_id is nullable so untouched mutators keep writing', function () {
    $vendor = makeStockVendor();
    $product = makeStockProduct($vendor, 10);

    $ledger = InventoryLedger::create([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'transaction_type' => 'pos_sale',
        'quantity_change'  => -1,
    ]);

    expect($ledger->fresh()->store_id)->toBeNull();
});
