<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runStockResync(): void
{
    (require database_path('migrations/2026_08_15_120000_resync_product_store_stock_from_products.php'))->up();
}

function resyncVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Resync Vendor '.uniqid(),
    ]);
}

// Products are written with the query builder here on purpose: these tests
// reproduce the pre-2b world, where the columns were the truth and no observer
// kept a store row in step.
function resyncProduct(Vendor $vendor, int $quantity, int $reserved = 0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Resync Product '.uniqid(),
        'price'          => 1000,
        'status'         => 'published',
    ]);

    DB::table('products')->where('id', $product->id)->update([
        'stock_quantity' => $quantity,
        'reserved_stock' => $reserved,
    ]);

    return $product->fresh();
}

test('a stale store row is overwritten with the current product numbers', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 29, 3);

    // The Phase 2a snapshot: correct when taken, four units behind since.
    // Query-builder update so the mirror is left holding the newer number,
    // which is exactly the disagreement the re-sync exists to settle.
    DB::table('product_store_stock')
        ->where('product_id', $product->id)
        ->update(['quantity' => 25, 'reserved' => 0]);

    runStockResync();

    $row = ProductStoreStock::where('product_id', $product->id)->first();

    expect($row->quantity)->toBe(29)
        ->and($row->reserved)->toBe(3)
        ->and(ProductStoreStock::where('product_id', $product->id)->count())->toBe(1);
});

test('a product with no store row at all gets one', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 14, 2);

    // Reproduces a product that predates the per-store rows entirely — before
    // ProductObserver existed, nothing opened a row at creation.
    DB::table('product_store_stock')->where('product_id', $product->id)->delete();

    expect(ProductStoreStock::where('product_id', $product->id)->count())->toBe(0);

    runStockResync();

    expect(ProductStoreStock::where('product_id', $product->id)->first())
        ->quantity->toBe(14)
        ->reserved->toBe(2);
});

test('the re-sync writes without firing the mirror observer', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 40, 5);

    runStockResync();

    // The columns are untouched by the re-sync — it reads them, never writes
    // them — and they already agree with what it wrote.
    expect($product->fresh()->stock_quantity)->toBe(40)
        ->and($product->fresh()->reserved_stock)->toBe(5);
});

test('it is idempotent', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 17, 1);

    runStockResync();
    runStockResync();
    runStockResync();

    expect(ProductStoreStock::where('product_id', $product->id)->count())->toBe(1)
        ->and(ProductStoreStock::where('product_id', $product->id)->first()->quantity)->toBe(17);
});

test('reconciliation holds for every product afterwards', function () {
    $vendor = resyncVendor();
    resyncProduct($vendor, 10, 1);
    resyncProduct($vendor, 0, 0);
    resyncProduct($vendor, 250, 40);

    runStockResync();

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('Mirror is exact')
        ->assertSuccessful();
});

test('it aborts loudly when a product already holds stock at a second store', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 30);

    // Writing the whole product total onto the default row would double-count
    // against this one, so the per-product check must refuse.
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);
    DB::table('product_store_stock')->insert([
        'product_id' => $product->id,
        'store_id'   => $second->id,
        'quantity'   => 12,
        'reserved'   => 0,
    ]);

    expect(fn () => runStockResync())
        ->toThrow(RuntimeException::class, 'do not reconcile');
});

test('a vendor without a default store gets one rather than blocking the run', function () {
    $vendor = resyncVendor();
    $product = resyncProduct($vendor, 8);
    Store::where('vendor_id', $vendor->id)->delete();

    runStockResync();

    expect(Store::where('vendor_id', $vendor->id)->where('is_default', true)->count())->toBe(1)
        ->and(ProductStoreStock::where('product_id', $product->id)->first()->quantity)->toBe(8);
});

test('a freshly seeded database reconciles', function () {
    // migrate:fresh --seed must not produce a drifted database. ProductSeeder
    // needs the techhaven vendor and the category rows, so the chain runs in
    // the same order DatabaseSeeder uses.
    $this->seed(Database\Seeders\VendorPermissionsSeeder::class);
    $this->seed(Database\Seeders\CategorySeeder::class);
    $this->seed(Database\Seeders\UserSeeder::class);
    $this->seed(Database\Seeders\VendorSeeder::class);
    $this->seed(Database\Seeders\ProductSeeder::class);

    expect(Product::count())->toBeGreaterThan(0)
        ->and(ProductStoreStock::count())->toBe(Product::count());

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('Mirror is exact')
        ->assertSuccessful();
});
