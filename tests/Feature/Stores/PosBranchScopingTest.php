<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function posVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'POS Vendor '.uniqid(),
    ]);
}

function posCashier(Vendor $vendor, ?Store $store = null): User
{
    $cashier = User::factory()->create();
    $vendor->users()->attach($cashier->id);

    if ($store) {
        $cashier->stores()->attach($store->id);
    }

    return $cashier;
}

function posProduct(Vendor $vendor, Store $home, int $qty, ?string $name = null): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name ?? 'POS Product '.uniqid(),
        'sku'            => 'SKU-'.strtoupper(uniqid()),
        'barcode'        => (string) random_int(1000000000000, 9999999999999),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $qty,
        'status'         => 'published',
    ]);
}

// ─── The catalogue the till receives ────────────────────────────────

test('a till receives only the products homed at its own branch', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    posProduct($vendor, $vendor->defaultStore, 10, 'Main Only');
    posProduct($vendor, $branch, 4, 'Branch Only');

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    $names = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")
        ->assertOk()->json())->pluck('name');

    // The other branch's stock never reaches the device, so it cannot be sold
    // from it even offline.
    expect($names)->toContain('Branch Only')
        ->not->toContain('Main Only');
});

test('a cashier at the default store receives only that store products', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    posProduct($vendor, $vendor->defaultStore, 10, 'Main Only');
    posProduct($vendor, $branch, 4, 'Branch Only');

    $cashier = posCashier($vendor, $vendor->defaultStore);
    Sanctum::actingAs($cashier, ['pos']);

    $names = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->json())->pluck('name');

    expect($names)->toContain('Main Only')->not->toContain('Branch Only');
});

test('a cashier with no store assignment falls back to the default branch', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    posProduct($vendor, $vendor->defaultStore, 10, 'Main Only');
    posProduct($vendor, $branch, 4, 'Branch Only');

    // Same resolution the sale path has used since Phase 4 — no second rule.
    $cashier = posCashier($vendor);
    Sanctum::actingAs($cashier, ['pos']);

    $names = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->json())->pluck('name');

    expect($names)->toContain('Main Only')->not->toContain('Branch Only');
});

test('a product with nothing left at the branch is not offered', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    posProduct($vendor, $branch, 0, 'Sold Out');
    posProduct($vendor, $branch, 3, 'In Stock');

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    $names = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->json())->pluck('name');

    expect($names)->toContain('In Stock')->not->toContain('Sold Out');
});

test('the quantities shown are the branch own', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    $product = posProduct($vendor, $branch, 7);
    ProductStoreStock::where('product_id', $product->id)->update(['reserved' => 2]);

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    $row = collect($this->getJson("/api/pos/products?vendor_id={$vendor->id}")->json())
        ->firstWhere('id', $product->id);

    expect($row['available_stock'])->toBe(5);
});

// ─── Search is scoped identically ───────────────────────────────────

test('search never returns another branch product', function () {
    $vendor = posVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    $other = posProduct($vendor, $vendor->defaultStore, 10, 'Elsewhere Widget');
    posProduct($vendor, $branch, 4, 'Here Widget');

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    // By name, and by exact barcode — the barcode path is how a scanner reaches
    // the server when the local cache misses.
    $byName = collect($this->getJson("/api/pos/products/search?vendor_id={$vendor->id}&q=Widget")->json())->pluck('name');
    $byBarcode = $this->getJson("/api/pos/products/search?vendor_id={$vendor->id}&q={$other->barcode}")->json();

    expect($byName)->toContain('Here Widget')->not->toContain('Elsewhere Widget')
        ->and($byBarcode)->toBe([]);
});

// ─── The server stays the authority on selling ──────────────────────

test('the server refuses a sale larger than the branch holds', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = posVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    $product = posProduct($vendor, $branch, 2);

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    $response = $this->postJson('/api/pos/sales', [
        'vendor_id' => $vendor->id,
        'items' => [[
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => 1000,
            'quantity'     => 5,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 5000,
        'payments'        => null,
    ]);

    // Rejected, and nothing moved: the guard is the store row, not the mirror.
    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(2);
});

test('a sale within the branch stock succeeds and decrements that branch', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = posVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);
    $product = posProduct($vendor, $branch, 6);

    $cashier = posCashier($vendor, $branch);
    Sanctum::actingAs($cashier, ['pos']);

    $this->postJson('/api/pos/sales', [
        'vendor_id' => $vendor->id,
        'items' => [[
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => 1000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 3000,
        'payments'        => null,
    ])->assertSuccessful();

    expect(ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $branch->id)->value('quantity'))->toBe(4);
});
