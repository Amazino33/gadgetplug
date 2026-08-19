<?php

use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Actions\Inventory\ReserveStockAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStoreAllocation;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\StoreAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function allocVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Alloc Vendor '.uniqid(),
    ]);
}

/** Stock at the default store, plus optional extra branches: ['Branch' => qty]. */
function allocProduct(Vendor $vendor, int $defaultQty, array $branches = []): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Alloc Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => $defaultQty]);

    foreach ($branches as $name => $qty) {
        $store = Store::create(['vendor_id' => $vendor->id, 'name' => $name]);
        ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => $qty]);
    }

    return $product->fresh();
}

function allocOrderItem(Product $product, int $quantity): OrderItem
{
    $order = Order::create([
        'reference' => 'ORD-'.strtoupper(uniqid()),
        'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 1000 * $quantity, 'status' => 'pending', 'payment_method' => 'pay_on_delivery',
    ]);

    return OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $product->vendor_id, 'quantity' => $quantity, 'unit_price' => 1000,
    ]);
}

function rowsFor(Product $product): \Illuminate\Support\Collection
{
    return ProductStoreStock::where('product_id', $product->id)->get()->keyBy('store_id');
}

// ─── Combined availability: the intended behaviour change ───────────

test('combined available spans every active store', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);

    expect(StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(8)
        // The storefront's mirror advertises the same number.
        ->and($product->fresh()->stock_quantity)->toBe(8);
});

test('an order for the full combined quantity succeeds', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $item = allocOrderItem($product, 8);

    app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 8, orderItemId: $item->id,
    );

    expect($product->fresh()->reserved_stock)->toBe(8)
        ->and($item->storeAllocations()->sum('quantity'))->toBe(8);
});

test('an order for more than the combined quantity is rejected and reserves nothing', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $item = allocOrderItem($product, 9);

    expect(fn () => app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 9, orderItemId: $item->id,
    ))->toThrow(Exception::class, 'Insufficient available stock');

    expect($product->fresh()->reserved_stock)->toBe(0)
        ->and(OrderItemStoreAllocation::count())->toBe(0);
});

// ─── The split ──────────────────────────────────────────────────────

test('a line too big for one store splits default-first, fewest stores', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $default = $vendor->defaultStore->id;
    $branch = Store::where('vendor_id', $vendor->id)->where('name', 'Branch B')->value('id');

    $item = allocOrderItem($product, 6);

    app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 6, orderItemId: $item->id,
    );

    $allocations = $item->storeAllocations()->pluck('quantity', 'store_id');
    $rows = rowsFor($product);

    expect($allocations[$default])->toBe(5)
        ->and($allocations[$branch])->toBe(1)
        ->and($rows[$default]->reserved)->toBe(5)
        ->and($rows[$branch]->reserved)->toBe(1)
        // Physical stock is untouched by a reservation.
        ->and($rows[$default]->quantity)->toBe(5)
        ->and($rows[$branch]->quantity)->toBe(3);
});

test('one store is used when one store can cover it, even if not the default', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 2, ['Big Branch' => 9]);
    $big = Store::where('vendor_id', $vendor->id)->where('name', 'Big Branch')->value('id');

    $item = allocOrderItem($product, 3);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 3, orderItemId: $item->id);

    // Fewest splits beats default-first when the default cannot cover it alone.
    expect($item->storeAllocations()->pluck('quantity', 'store_id')->all())->toBe([$big => 3]);
});

test('the default store is preferred when it can cover the line alone', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 10, ['Bigger Branch' => 50]);
    $item = allocOrderItem($product, 4);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 4, orderItemId: $item->id);

    expect($item->storeAllocations()->pluck('quantity', 'store_id')->all())
        ->toBe([$vendor->defaultStore->id => 4]);
});

test('an inactive store is never allocated from', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 2, ['Closed Branch' => 100]);
    Store::where('name', 'Closed Branch')->update(['is_active' => false]);

    expect(StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(2);

    $item = allocOrderItem($product, 3);
    expect(fn () => app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 3, orderItemId: $item->id,
    ))->toThrow(Exception::class, 'Insufficient available stock');
});

test('already-reserved units are not offered twice', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);

    $first = allocOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $first->id);

    expect(StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(2);

    $second = allocOrderItem($product, 3);
    expect(fn () => app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 3, orderItemId: $second->id,
    ))->toThrow(Exception::class, 'Insufficient available stock');
});

// ─── Dispatch and release follow the allocation ─────────────────────

test('dispatch decrements each allocated store', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $default = $vendor->defaultStore->id;
    $branch = Store::where('name', 'Branch B')->value('id');

    $item = allocOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);

    $rows = rowsFor($product);

    expect($rows[$default]->quantity)->toBe(0)
        ->and($rows[$default]->reserved)->toBe(0)
        ->and($rows[$branch]->quantity)->toBe(2)
        ->and($rows[$branch]->reserved)->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(2);
});

test('dispatch writes one ledger row per store touched', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $item = allocOrderItem($product, 6);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);

    $dispatched = InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'dispatched')->get();

    expect($dispatched)->toHaveCount(2)
        ->and($dispatched->pluck('store_id')->filter()->count())->toBe(2)
        ->and((int) $dispatched->sum('quantity_change'))->toBe(-6);
});

test('release restores each allocated store and clears the allocation', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $default = $vendor->defaultStore->id;
    $branch = Store::where('name', 'Branch B')->value('id');

    $item = allocOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);

    $rows = rowsFor($product);

    expect($rows[$default]->reserved)->toBe(0)
        ->and($rows[$branch]->reserved)->toBe(0)
        ->and($rows[$default]->quantity)->toBe(5)
        ->and($rows[$branch]->quantity)->toBe(3)
        ->and($item->storeAllocations()->count())->toBe(0)
        ->and(StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(8);
});

test('releasing twice is harmless', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $item = allocOrderItem($product, 6);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);

    expect($product->fresh()->reserved_stock)->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(8);
});

test('a released line can be reserved again and re-splits cleanly', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);
    $item = allocOrderItem($product, 6);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);

    expect($item->storeAllocations()->sum('quantity'))->toBe(6)
        ->and($item->storeAllocations()->count())->toBe(2)
        ->and($product->fresh()->reserved_stock)->toBe(6);
});

// ─── Single-store parity ────────────────────────────────────────────

test('a single-store vendor behaves exactly as before through the whole cycle', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 10);
    $only = $vendor->defaultStore->id;
    $item = allocOrderItem($product, 4);

    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 4, orderItemId: $item->id);

    expect($item->storeAllocations()->pluck('quantity', 'store_id')->all())->toBe([$only => 4])
        ->and($product->fresh()->stock_quantity)->toBe(10)
        ->and($product->fresh()->reserved_stock)->toBe(4);

    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 4, orderItemId: $item->id);

    expect($product->fresh()->stock_quantity)->toBe(6)
        ->and($product->fresh()->reserved_stock)->toBe(0);
});

test('a single-store vendor is still refused beyond its stock', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 3);
    $item = allocOrderItem($product, 4);

    expect(fn () => app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 4, orderItemId: $item->id,
    ))->toThrow(Exception::class, 'Insufficient available stock');
});

// ─── Backfill and mirror ────────────────────────────────────────────

test('the backfill gives every existing line one default-store allocation', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 10);
    $item = allocOrderItem($product, 3);

    // A line from before this phase.
    OrderItemStoreAllocation::where('order_item_id', $item->id)->delete();

    (require database_path('migrations/2026_08_16_100001_backfill_order_item_store_allocations.php'))->up();

    expect($item->storeAllocations()->pluck('quantity', 'store_id')->all())
        ->toBe([$vendor->defaultStore->id => 3]);
});

test('the backfill aborts when allocations do not sum to the line', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 10);
    $item = allocOrderItem($product, 3);

    // Reserve first, so there is a real allocation to corrupt — the backfill
    // skips lines that already have one, so a line with none would simply be
    // filled in correctly and never trip the assertion.
    app(ReserveStockAction::class)->execute(
        productId: $product->id, quantity: 3, orderItemId: $item->id,
    );

    DB::table('order_item_store_allocations')
        ->where('order_item_id', $item->id)
        ->update(['quantity' => 99]);

    expect(fn () => (require database_path('migrations/2026_08_16_100001_backfill_order_item_store_allocations.php'))->up())
        ->toThrow(RuntimeException::class, 'do not sum to the line quantity');
});

test('the stock mirror stays exact across a split reserve, dispatch and release', function () {
    $vendor = allocVendor();
    $product = allocProduct($vendor, 5, ['Branch B' => 3]);

    $a = allocOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $a->id);
    app(DispatchStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $a->id);

    $b = allocOrderItem($product, 2);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 2, orderItemId: $b->id);
    app(ReleaseReservationAction::class)->execute(productId: $product->id, quantity: 2, orderItemId: $b->id);

    // Asserted directly rather than through stock:verify-mirror. This scenario
    // deliberately puts ONE product's stock in two branches to exercise the
    // splitting path, and Phase 8 made that shape invalid: a product now
    // belongs to exactly one home store, and the command flags stock sitting
    // anywhere else. The property this test was written for — that the mirror
    // equals the sum of the rows through a split reserve, dispatch and release
    // — is unchanged, so it is checked here on its own terms.
    $sumQuantity = (int) ProductStoreStock::where('product_id', $product->id)->sum('quantity');
    $sumReserved = (int) ProductStoreStock::where('product_id', $product->id)->sum('reserved');

    expect($product->fresh()->stock_quantity)->toBe($sumQuantity)
        ->and($product->fresh()->reserved_stock)->toBe($sumReserved);
});
