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

function moveVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Move Vendor '.uniqid(),
    ]);
}

function moveProduct(Vendor $vendor, Store $home, int $qty, int $reserved = 0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Move Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => $qty,
        'status'         => 'published',
    ]);

    if ($reserved > 0) {
        ProductStoreStock::where('product_id', $product->id)->update(['reserved' => $reserved]);
    }

    return $product->fresh();
}

// ─── The move ───────────────────────────────────────────────────────

test('re-homing a product carries all its stock to the new branch', function () {
    $vendor = moveVendor();
    $from = $vendor->defaultStore;
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $from, 14);

    $product->update(['store_id' => $to->id]);

    $rows = ProductStoreStock::where('product_id', $product->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->store_id)->toBe($to->id)
        ->and($rows->first()->quantity)->toBe(14)
        // Nothing is left behind at the old branch.
        ->and(ProductStoreStock::where('store_id', $from->id)->where('product_id', $product->id)->count())->toBe(0);
});

test('the vendor total is unchanged by a move', function () {
    $vendor = moveVendor();
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $vendor->defaultStore, 9);

    $before = $product->fresh()->stock_quantity;
    $product->update(['store_id' => $to->id]);

    // A move relocates stock; it neither creates nor destroys any.
    expect($product->fresh()->stock_quantity)->toBe($before)->toBe(9);
});

test('stock already at the destination is added to, not replaced', function () {
    $vendor = moveVendor();
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $vendor->defaultStore, 5);

    // A stray row at the destination, as a pre-existing multi-store product
    // would have had.
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $to->id, 'quantity' => 3]);

    $product->update(['store_id' => $to->id]);

    $rows = ProductStoreStock::where('product_id', $product->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->quantity)->toBe(8);
});

test('a move writes one ledger entry per branch, naming where the stock went', function () {
    $vendor = moveVendor();
    $from = $vendor->defaultStore;
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $from, 6);

    $product->update(['store_id' => $to->id]);

    $entries = InventoryLedger::where('product_id', $product->id)
        ->where('transaction_type', 'store_transfer')
        ->get()
        ->keyBy('store_id');

    expect($entries)->toHaveCount(2)
        ->and($entries[$from->id]->quantity_change)->toBe(-6)
        ->and($entries[$to->id]->quantity_change)->toBe(6)
        ->and($entries[$from->id]->description)->toContain('New Home')
        // Each branch's own log tells the truth on its own.
        ->and((int) $entries->sum('quantity_change'))->toBe(0);
});

test('moving an empty product writes no ledger noise', function () {
    $vendor = moveVendor();
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $vendor->defaultStore, 0);

    $product->update(['store_id' => $to->id]);

    expect(InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'store_transfer')->count())->toBe(0)
        // The product still moved, it just carried nothing.
        ->and(ProductStoreStock::where('product_id', $product->id)->value('store_id'))->toBe($to->id);
});

// ─── The guard ──────────────────────────────────────────────────────

test('a product cannot be re-homed while stock is reserved for orders', function () {
    $vendor = moveVendor();
    $from = $vendor->defaultStore;
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $from, 10, reserved: 3);

    expect(fn () => $product->update(['store_id' => $to->id]))
        ->toThrow(LogicException::class, 'reserved for orders');

    // Refused outright: neither record of "which branch" moved.
    expect($product->fresh()->store_id)->toBe($from->id)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('store_id'))->toBe($from->id)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('reserved'))->toBe(3);
});

test('the same product moves freely once the reservation clears', function () {
    $vendor = moveVendor();
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $vendor->defaultStore, 10, reserved: 3);

    ProductStoreStock::where('product_id', $product->id)->first()->update(['reserved' => 0]);

    $product->fresh()->update(['store_id' => $to->id]);

    expect($product->fresh()->store_id)->toBe($to->id)
        ->and(ProductStoreStock::where('product_id', $product->id)->value('quantity'))->toBe(10);
});

test('an unrelated edit is never blocked by the guard', function () {
    $vendor = moveVendor();
    $product = moveProduct($vendor, $vendor->defaultStore, 10, reserved: 4);

    $product->update(['name' => 'Renamed While Reserved']);

    expect($product->fresh()->name)->toBe('Renamed While Reserved');
});

// ─── The invariant, and the command that guards it ──────────────────

test('verify-mirror passes when every product sits at its home store', function () {
    $vendor = moveVendor();
    $to = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Home']);
    $product = moveProduct($vendor, $vendor->defaultStore, 7);
    $product->update(['store_id' => $to->id]);

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('Mirror is exact')
        ->assertSuccessful();
});

test('verify-mirror catches stock stranded away from its home store', function () {
    $vendor = moveVendor();
    $elsewhere = Store::create(['vendor_id' => $vendor->id, 'name' => 'Elsewhere']);
    $product = moveProduct($vendor, $vendor->defaultStore, 5);

    // Exactly what a half-done move would leave behind — the product homed in
    // one branch with its units sitting in another, invisible to both.
    DB::table('product_store_stock')->where('product_id', $product->id)->update(['store_id' => $elsewhere->id]);

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('away from their home store')
        ->assertFailed();
});

test('verify-mirror catches a product with no home store at all', function () {
    $vendor = moveVendor();
    $product = moveProduct($vendor, $vendor->defaultStore, 5);

    // NULL comparisons are neither true nor false, so this is the case a naive
    // != check would wave through.
    DB::table('products')->where('id', $product->id)->update(['store_id' => null]);

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('away from their home store')
        ->assertFailed();
});
