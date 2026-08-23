<?php

use App\Actions\Inventory\ReserveStockAction;
use App\Jobs\ReleaseStaleReservationsJob;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A reservation is only ever released today by explicit cancellation
// (OrderObserver). This job exists for the order nobody ever cancels — a
// rider who never comes, a customer who goes quiet after paying — whose held
// stock would otherwise sit walled off from every other buyer forever.

function staleReservationContext(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Stale Reservation Store']);
    $store    = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main', 'is_default' => true]);
    $category = Category::create(['name' => 'Stale Reservation Category '.uniqid()]);
    $product  = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Reserved Widget', 'price' => 5000, 'store_id' => $store->id,
        'stock_quantity' => 10, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'store', 'category', 'product');
}

function reservedOrder(array $c, string $status = 'paid', int $quantity = 3): Order
{
    $order = Order::create([
        'reference' => 'GP-'.uniqid(), 'customer_name' => 'Buyer',
        'customer_email' => 'buyer@example.com', 'customer_phone' => '08040000000',
        'shipping_address' => 'Uyo', 'total_amount' => $quantity * 5000,
        'status' => $status, 'payment_method' => 'paystack',
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $c['product']->id,
        'vendor_id' => $c['vendor']->id, 'quantity' => $quantity, 'unit_price' => 5000,
    ]);

    app(ReserveStockAction::class)->execute(
        productId: $c['product']->id, quantity: $quantity,
        reference: $order->reference, orderItemId: $item->id, store: $c['store']->id,
    );

    return $order->fresh();
}

it('stamps reserved_at the moment stock is actually reserved for an order', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c);

    expect($order->reserved_at)->not->toBeNull();
});

it('auto-releases a reservation held for over 24 hours with no dispatch or cancellation', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c, 'paid', 3);

    $this->travelTo($order->reserved_at->copy()->addHours(25));

    (new ReleaseStaleReservationsJob())->handle();

    $row = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['store']->id)->first();

    expect($row->reserved)->toBe(0)
        ->and($order->fresh()->reservation_released_at)->not->toBeNull()
        // The order itself is not cancelled — the goods might still be
        // handed over; only the hold is lifted.
        ->and($order->fresh()->status)->toBe('paid');
});

it('leaves a reservation alone before the 24 hour window has passed', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c, 'confirmed', 2);

    $this->travelTo($order->reserved_at->copy()->addHours(23));

    (new ReleaseStaleReservationsJob())->handle();

    $row = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['store']->id)->first();

    expect($row->reserved)->toBe(2)
        ->and($order->fresh()->reservation_released_at)->toBeNull();
});

it('never releases the same order twice', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c, 'paid', 3);

    $this->travelTo($order->reserved_at->copy()->addHours(25));

    (new ReleaseStaleReservationsJob())->handle();
    (new ReleaseStaleReservationsJob())->handle();

    $row = ProductStoreStock::where('product_id', $c['product']->id)->where('store_id', $c['store']->id)->first();

    expect($row->reserved)->toBe(0);
});

it('does not touch a shipped order — dispatch already resolved its reservation', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c, 'shipped', 3);

    $this->travelTo($order->reserved_at->copy()->addHours(25));

    (new ReleaseStaleReservationsJob())->handle();

    expect($order->fresh()->reservation_released_at)->toBeNull();
});

it('notifies the vendor when a reservation is auto-released', function () {
    $c     = staleReservationContext();
    $order = reservedOrder($c, 'paid', 3);

    $this->travelTo($order->reserved_at->copy()->addHours(25));

    (new ReleaseStaleReservationsJob())->handle();

    expect($c['owner']->fresh()->notifications()->count())->toBe(1)
        ->and($c['owner']->fresh()->notifications()->first()->data['title'])->toContain($order->reference);
});
