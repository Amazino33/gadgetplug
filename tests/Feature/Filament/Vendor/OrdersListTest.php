<?php

use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpOrdersListVendor(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Orders List Store']);

    return compact('owner', 'vendor');
}

function actAsOrdersListVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

function makeOrdersListOrder(Vendor $vendor, string $status, string $reference): Order
{
    $category = Category::create(['name' => 'Orders List Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Orders List Product',
        'price'          => 3000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => $reference,
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => 'Uyo, Akwa Ibom State — 1 Test Street',
        'local_government' => 'Uyo',
        'total_amount'     => 3000,
        'status'           => $status,
        'payment_method'   => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 1,
        'unit_price' => 3000,
    ]);

    return $order;
}

test('the default orders list excludes delivered and cancelled orders', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-PENDING');
    makeOrdersListOrder($data['vendor'], 'delivered', 'ORD-DELIVERED');
    makeOrdersListOrder($data['vendor'], 'cancelled', 'ORD-CANCELLED');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->assertSee('ORD-PENDING')
        ->assertDontSee('ORD-DELIVERED')
        ->assertDontSee('ORD-CANCELLED');
});

test('the "all orders" filter shows delivered and cancelled orders too', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-PENDING');
    makeOrdersListOrder($data['vendor'], 'delivered', 'ORD-DELIVERED');
    makeOrdersListOrder($data['vendor'], 'cancelled', 'ORD-CANCELLED');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->set('statusFilter', 'all')
        ->assertSee('ORD-PENDING')
        ->assertSee('ORD-DELIVERED')
        ->assertSee('ORD-CANCELLED');
});

test('filtering by a specific status like delivered shows only that status', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-PENDING');
    makeOrdersListOrder($data['vendor'], 'delivered', 'ORD-DELIVERED');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->set('statusFilter', 'delivered')
        ->assertSee('ORD-DELIVERED')
        ->assertDontSee('ORD-PENDING');
});

test('searching by reference finds the matching order', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-FINDME');
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-OTHER');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->set('search', 'FINDME')
        ->assertSee('ORD-FINDME')
        ->assertDontSee('ORD-OTHER');
});

test('updateOrderStatus applies an allowed transition', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-TRANSITION');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'shipped');

    expect($order->fresh()->status)->toBe('shipped');
});

test('updateOrderStatus rejects a transition that is not offered for the current status', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-INVALID');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'delivered');

    expect($order->fresh()->status)->toBe('pending');
});

test('the order tile/row shows its local government area', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-LGA-TEST');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->set('statusFilter', 'all')
        ->assertSee('Uyo');
});

test('a note can be added from the orders list without changing status', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-NOTE-ONLY');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, null, 'Tried to call, no answer. Will retry later.');

    expect($order->fresh()->status)->toBe('pending');

    $note = OrderNote::where('order_id', $order->id)->first();

    expect($note)->not->toBeNull()
        ->and($note->body)->toBe('Tried to call, no answer. Will retry later.')
        ->and($note->vendor_id)->toBe($data['vendor']->id)
        ->and($note->user_id)->toBe($data['owner']->id);
});

test('a note can be added together with a status change in the same call', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-NOTE-AND-STATUS');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->call('updateOrderStatus', $order->id, 'shipped', 'Customer asked for evening delivery.');

    expect($order->fresh()->status)->toBe('shipped')
        ->and(OrderNote::where('order_id', $order->id)->where('body', 'Customer asked for evening delivery.')->exists())->toBeTrue();
});

test('multiple notes on the same order accumulate rather than overwrite', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-NOTE-HISTORY');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, null, 'First note.');
    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, null, 'Second note.');

    expect(OrderNote::where('order_id', $order->id)->count())->toBe(2)
        ->and($order->fresh()->notes->pluck('body')->all())->toBe(['Second note.', 'First note.']);
});

test('submitting neither a status nor a note does nothing', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-NOTHING');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, null, null);

    expect($order->fresh()->status)->toBe('pending')
        ->and(OrderNote::where('order_id', $order->id)->exists())->toBeFalse();
});

test('the order row and tile include a call link', function () {
    $data = setUpOrdersListVendor();
    makeOrdersListOrder($data['vendor'], 'pending', 'ORD-CALL-TEST');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)
        ->assertSee('tel:08040000000', escape: false);
});

test('notes added from the orders list are visible on the order detail page', function () {
    $data  = setUpOrdersListVendor();
    $order = makeOrdersListOrder($data['vendor'], 'pending', 'ORD-DETAIL-NOTES');

    actAsOrdersListVendor($data);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $order->id, null, 'Rescheduled to evening delivery.');

    Livewire::test(\App\Filament\Vendor\Resources\Orders\Pages\ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('Rescheduled to evening delivery.');
});
