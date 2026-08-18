<?php

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function homeVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Home Vendor '.uniqid(),
    ]);
}

function homeProduct(Vendor $vendor, ?Store $store = null, int $qty = 0): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store?->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Home Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $qty,
        'status'         => 'published',
    ]);
}

function runHomeStoreBackfill(): void
{
    (require database_path('migrations/2026_08_18_100000_add_store_id_to_products_table.php'))->up();
}

function actAsHome(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

// ─── The link itself ────────────────────────────────────────────────

test('a product belongs to one home store', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = homeProduct($vendor, $branch);

    expect($product->store->id)->toBe($branch->id)
        ->and($product->store->name)->toBe('Branch');
});

test('opening stock is created at the home store, not the vendor default', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = homeProduct($vendor, $branch, qty: 12);

    $rows = ProductStoreStock::where('product_id', $product->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_id)->toBe($branch->id)
        ->and($rows->first()->quantity)->toBe(12)
        // Identity and quantity agree from the first moment.
        ->and($product->fresh()->stock_quantity)->toBe(12);
});

test('a product created without a named home still lands at the vendor default', function () {
    $vendor = homeVendor();
    $product = homeProduct($vendor, qty: 5);

    expect(ProductStoreStock::where('product_id', $product->id)->value('store_id'))
        ->toBe($vendor->defaultStore->id);
});

// ─── The backfill ───────────────────────────────────────────────────

test('the backfill homes a product where its stock already sits', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = homeProduct($vendor, $branch, qty: 4);

    // A product from before the column existed.
    DB::table('products')->where('id', $product->id)->update(['store_id' => null]);

    runHomeStoreBackfill();

    expect($product->fresh()->store_id)->toBe($branch->id);
});

test('a product holding stock in two stores is homed where the most sits', function () {
    $vendor = homeVendor();
    $big = Store::create(['vendor_id' => $vendor->id, 'name' => 'Big Branch']);
    $product = homeProduct($vendor, qty: 2);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $big->id, 'quantity' => 40]);

    DB::table('products')->where('id', $product->id)->update(['store_id' => null]);

    runHomeStoreBackfill();

    expect($product->fresh()->store_id)->toBe($big->id);
});

test('a product with no stock row at all falls back to the vendor default', function () {
    $vendor = homeVendor();
    $product = homeProduct($vendor);
    ProductStoreStock::where('product_id', $product->id)->delete();
    DB::table('products')->where('id', $product->id)->update(['store_id' => null]);

    runHomeStoreBackfill();

    expect($product->fresh()->store_id)->toBe($vendor->defaultStore->id);
});

test('the backfill is idempotent and never re-homes a product', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = homeProduct($vendor, $branch, qty: 3);

    runHomeStoreBackfill();
    runHomeStoreBackfill();

    expect($product->fresh()->store_id)->toBe($branch->id);
});

test('the backfill aborts if a home store would point at another vendor', function () {
    $vendorA = homeVendor();
    $vendorB = homeVendor();
    $product = homeProduct($vendorA);

    // The exact corruption the assertion exists to refuse.
    DB::table('products')->where('id', $product->id)->update(['store_id' => $vendorB->defaultStore->id]);

    expect(fn () => runHomeStoreBackfill())
        ->toThrow(RuntimeException::class, 'without a home store in their own vendor');
});

// ─── The form ───────────────────────────────────────────────────────

test('an owner picks the home store when creating a product', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Chosen Branch']);
    $category = Category::create(['name' => 'Cat '.uniqid()]);

    actAsHome($vendor, $vendor->user);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Owner Picked', 'category_id' => $category->id,
            'price' => 1000, 'store_id' => $branch->id, 'status' => 'published',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Owner Picked')->first();

    expect($product->store_id)->toBe($branch->id)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('store_id'))->toBe($branch->id);
});

test('a product created with no home falls back to the branch being worked in', function () {
    $vendor = homeVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Working Branch']);

    actAsHome($vendor, $vendor->user);
    ActiveStore::set($vendor, $vendor->user, $branch->id);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'store_id'  => ActiveStore::currentId(),
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Fallback Homed', 'price' => 1000, 'status' => 'published',
    ]);

    expect($product->store_id)->toBe($branch->id);
});
