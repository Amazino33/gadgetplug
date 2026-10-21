<?php

use App\Filament\Vendor\Resources\OrderItems\Pages\ViewOrderItem;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

// The Order Items page has its own "Update Status" action, entirely separate
// from the Orders list and the order's own detail page. Since the status
// service (Prompt 3, Phase 3) it offers the store's steps only — packed and
// handed to rider. Delivered and the money received are GadgetPlug's to record
// from the admin panel, so nothing here can recognise revenue.
uses(RefreshDatabase::class);

function setUpOrderItemRevenueVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Order Item Revenue Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Order Item Revenue Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Order Item Revenue Product', 'price' => 5000, 'stock_quantity' => 10, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function makeOrderItemRevenueOrder(array $data, string $paymentMethod, string $status): OrderItem
{
    $order = Order::create([
        'reference' => 'ORD-OIR-' . uniqid(),
        'customer_name' => 'Jane Customer', 'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => '1 Test Street',
        'total_amount' => 5000, 'status' => 'pending', 'payment_method' => $paymentMethod,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => 1, 'unit_price' => 5000,
    ]);

    $path = match ($paymentMethod . ':' . $status) {
        'pay_on_delivery:shipped' => ['confirmed', 'shipped'],
        default                   => [$status],
    };

    foreach ($path as $step) {
        $order->update(['status' => $step]);
    }

    return $item->fresh();
}

function actAsOrderItemRevenueOwner(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('the order item page cannot mark an order delivered', function () {
    $data = setUpOrderItemRevenueVendor();
    $item = makeOrderItemRevenueOrder($data, 'pay_on_delivery', 'shipped');
    actAsOrderItemRevenueOwner($data);

    // Nothing left for the store to do once the rider has it: the action is gone.
    Livewire::test(ViewOrderItem::class, ['record' => $item->getRouteKey()])
        ->assertActionHidden('updateStatus');

    expect($item->order->fresh()->status)->toBe('shipped')
        ->and($item->order->fresh()->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('handing over from the order item page records who did it and books no revenue', function () {
    $data = setUpOrderItemRevenueVendor();
    $item = makeOrderItemRevenueOrder($data, 'pay_on_delivery', 'confirmed');
    actAsOrderItemRevenueOwner($data);

    Livewire::test(ViewOrderItem::class, ['record' => $item->getRouteKey()])
        ->callAction('updateStatus', data: ['status' => 'packed']);

    expect($item->order->fresh()->status)->toBe('packed')
        ->and($item->order->fresh()->packed_by)->toBe($data['owner']->id);

    Livewire::test(ViewOrderItem::class, ['record' => $item->getRouteKey()])
        ->callAction('updateStatus', data: ['status' => 'shipped']);

    $order = $item->order->fresh();

    expect($order->status)->toBe('shipped')
        ->and($order->handed_over_by)->toBe($data['owner']->id)
        ->and($order->isRevenueRecognized())->toBeFalse()
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

test('a prepaid order is never blocked by the channel check from the order item page', function () {
    $data = setUpOrderItemRevenueVendor();
    $item = makeOrderItemRevenueOrder($data, 'paystack', 'paid');
    actAsOrderItemRevenueOwner($data);

    // 'shipped' is a valid next step for a paid order per the action's own
    // status options — no channel field should even be required here.
    Livewire::test(ViewOrderItem::class, ['record' => $item->getRouteKey()])
        ->callAction('updateStatus', data: ['status' => 'shipped']);

    expect($item->order->fresh()->status)->toBe('shipped');
});

test('non-delivery transitions from the order item page never ask for a channel', function () {
    $data = setUpOrderItemRevenueVendor();
    $item = makeOrderItemRevenueOrder($data, 'pay_on_delivery', 'confirmed');
    actAsOrderItemRevenueOwner($data);

    Livewire::test(ViewOrderItem::class, ['record' => $item->getRouteKey()])
        ->callAction('updateStatus', data: ['status' => 'shipped']);

    expect($item->order->fresh()->status)->toBe('shipped');
});
