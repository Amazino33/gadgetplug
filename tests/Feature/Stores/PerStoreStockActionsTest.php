<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\Inventory\ReserveStockAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stockVendor(): Vendor
{
    // The VendorObserver seeds the default "Main Store" — the store every one
    // of these actions resolves to when no store is named.
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Stock Actions Vendor '.uniqid(),
    ]);
}

function stockProduct(Vendor $vendor, int $quantity = 0, int $reserved = 0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Stock Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 0,
        'reserved_stock' => 0,
        'status'         => 'published',
    ]);

    // ProductObserver already opened the default-store row at zero, so this
    // sets the opening stock on that row rather than creating a second one.
    // Through the model, so ProductStoreStockObserver carries it to the mirror.
    ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $vendor->defaultStore->id)
        ->first()
        ->update(['quantity' => $quantity, 'reserved' => $reserved]);

    return $product->fresh();
}

function storeRow(Product $product, ?Store $store = null): ProductStoreStock
{
    return ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', ($store ?? $product->vendor->defaultStore)->id)
        ->first();
}

// ─── The mirror ─────────────────────────────────────────────────────

test('seeding a store row drives the product mirror', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 40, 6);

    expect($product->stock_quantity)->toBe(40)
        ->and($product->reserved_stock)->toBe(6);
});

test('the mirror is the sum across every store the product sits in', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10, 2);

    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);
    ProductStoreStock::create([
        'product_id' => $product->id,
        'store_id'   => $second->id,
        'quantity'   => 25,
        'reserved'   => 3,
    ]);

    expect($product->fresh()->stock_quantity)->toBe(35)
        ->and($product->fresh()->reserved_stock)->toBe(5);
});

test('deleting a store row shrinks the mirror', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10);

    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);
    $row = ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $second->id, 'quantity' => 15]);

    expect($product->fresh()->stock_quantity)->toBe(25);

    $row->delete();

    expect($product->fresh()->stock_quantity)->toBe(10);
});

// ─── Each of the five writers ───────────────────────────────────────

test('adjust moves the store row and the mirror follows', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 20);

    app(AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: 5, transactionType: 'restock',
    );

    expect(storeRow($product)->quantity)->toBe(25)
        ->and($product->fresh()->stock_quantity)->toBe(25);
});

test('adjust refuses to drive a store negative', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 3);

    expect(fn () => app(AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: -4, transactionType: 'pos_sale',
    ))->toThrow(Exception::class, 'Insufficient stock');

    expect(storeRow($product)->quantity)->toBe(3)
        ->and($product->fresh()->stock_quantity)->toBe(3);
});

test('reserve raises reserved on the store row only', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 4);

    expect(storeRow($product))->quantity->toBe(10)->reserved->toBe(4)
        ->and($product->fresh()->stock_quantity)->toBe(10)
        ->and($product->fresh()->reserved_stock)->toBe(4);
});

test('reserve checks availability against the store, not the vendor total', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 2);

    // Plenty in another store — must not make this one reservable.
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Warehouse']);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $second->id, 'quantity' => 500]);

    expect($product->fresh()->stock_quantity)->toBe(502);

    expect(fn () => app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 5))
        ->toThrow(Exception::class, 'Insufficient available stock');
});

test('reserve counts existing reservations in availability', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10, 8);

    expect(fn () => app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 3))
        ->toThrow(Exception::class, 'Insufficient available stock');

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 2);

    expect(storeRow($product)->reserved)->toBe(10);
});

test('release lowers reserved and never goes below zero', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10, 3);

    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 10);

    expect(storeRow($product))->reserved->toBe(0)->quantity->toBe(10)
        ->and($product->fresh()->reserved_stock)->toBe(0);
});

test('dispatch drops quantity and clears the reservation together', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10, 4);

    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 3);

    expect(storeRow($product))->quantity->toBe(7)->reserved->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(7)
        ->and($product->fresh()->reserved_stock)->toBe(1);
});

test('dispatching more than is reserved clamps the reservation at zero', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10, 1);

    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 4);

    expect(storeRow($product))->quantity->toBe(6)->reserved->toBe(0);
});

test('procurement approval adds to the store row and the mirror', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 5);

    $supplier = App\Models\Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Supplier '.uniqid()]);
    $procurement = App\Models\Procurement::create([
        'vendor_id'   => $vendor->id,
        'supplier_id' => $supplier->id,
        'reference'   => 'PO-'.uniqid(),
        'status'      => 'pending',
        'created_by'  => $vendor->user_id,
    ]);
    $procurement->items()->create([
        'product_id'    => $product->id,
        'quantity'      => 12,
        'unit_cost'     => 600,
        'selling_price' => 1000,
    ]);

    app(App\Actions\Procurement\ApproveProcurementAction::class)->execute($procurement);

    expect(storeRow($product)->quantity)->toBe(17)
        ->and($product->fresh()->stock_quantity)->toBe(17);
});

// ─── Explicit store targeting ───────────────────────────────────────

test('an explicitly named store is the one that moves', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10);
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    app(AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: 7, transactionType: 'restock', store: $second,
    );

    expect(storeRow($product)->quantity)->toBe(10)
        ->and(storeRow($product, $second)->quantity)->toBe(7)
        ->and($product->fresh()->stock_quantity)->toBe(17);
});

test('a store with no row yet is created on first movement', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 10);
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Fresh Branch']);

    expect(ProductStoreStock::where('store_id', $second->id)->count())->toBe(0);

    app(AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: 4, transactionType: 'restock', store: $second->id,
    );

    expect(storeRow($product, $second)->quantity)->toBe(4)
        ->and($product->fresh()->stock_quantity)->toBe(14);
});

// ─── Ledger ─────────────────────────────────────────────────────────

test('every action stamps the ledger with the store it moved', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 20, 0);
    $defaultStoreId = $vendor->defaultStore->id;

    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: 5, transactionType: 'restock');
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 3);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 1);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 2);

    $ledger = InventoryLedger::where('product_id', $product->id)->orderBy('id')->get();

    expect($ledger)->toHaveCount(4)
        ->and($ledger->pluck('store_id')->unique()->all())->toBe([$defaultStoreId])
        ->and($ledger->pluck('transaction_type')->all())
        ->toBe(['restock', 'reserved', 'reservation_released', 'dispatched']);
});

// ─── Behaviour parity and reconciliation ────────────────────────────

test('a single-store vendor ends exactly where the old column arithmetic would', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 25, 0);

    // The same sequence walked in the Phase 2a panel check: +5, reserve 2,
    // release 1, dispatch 1 → quantity 29, reserved 0.
    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: 5, transactionType: 'restock');
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 2);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 1);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 1);

    expect($product->fresh()->stock_quantity)->toBe(29)
        ->and($product->fresh()->reserved_stock)->toBe(0)
        ->and($product->fresh()->available_stock)->toBe(29);
});

test('the mirror holds across many writes over several stores', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 50, 5);
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch Two']);

    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: 30, transactionType: 'restock', store: $second);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 4, store: $second);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 2, store: $second);
    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: -10, transactionType: 'pos_sale');
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 2);

    $fresh = $product->fresh();
    $sumQuantity = (int) ProductStoreStock::where('product_id', $product->id)->sum('quantity');
    $sumReserved = (int) ProductStoreStock::where('product_id', $product->id)->sum('reserved');

    expect($fresh->stock_quantity)->toBe($sumQuantity)
        ->and($fresh->reserved_stock)->toBe($sumReserved)
        ->and($sumQuantity)->toBe(68)   // 50-10 default + 30-2 second
        ->and($sumReserved)->toBe(5);   // (5-2 default) + (4-2 second)
});

test('a failed action leaves neither the store row nor the mirror changed', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 4);

    try {
        app(AdjustStockAction::class)->execute(
            productId: $product->id, quantityChanged: -9, transactionType: 'pos_sale',
        );
    } catch (Exception) {
        // expected
    }

    expect(storeRow($product)->quantity)->toBe(4)
        ->and($product->fresh()->stock_quantity)->toBe(4)
        ->and(InventoryLedger::where('product_id', $product->id)->count())->toBe(0);
});

test('the verify-mirror command passes on a healthy database', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 12, 2);
    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: 3, transactionType: 'restock');

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('Mirror is exact')
        ->assertSuccessful();
});

test('the verify-mirror command catches a hand-corrupted mirror', function () {
    $vendor = stockVendor();
    $product = stockProduct($vendor, 12);

    // Exactly what the command exists to notice: something wrote the column
    // directly, behind the per-store rows' back.
    DB::table('products')->where('id', $product->id)->update(['stock_quantity' => 999]);

    $this->artisan('stock:verify-mirror')
        ->expectsOutputToContain('drifted from their store rows')
        ->assertFailed();
});
