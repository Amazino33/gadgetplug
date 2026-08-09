<?php

use App\Filament\Vendor\Resources\OrderItems\Pages\ViewOrderItem;
use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpStockEffectsFixture(string $initialStatus = 'paid'): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Stock Effects Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Stock Effects Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Stock Effects Product',
        'price'          => 2000,
        'stock_quantity' => 10,
        'reserved_stock' => 3,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'ORD-STOCK-' . uniqid(),
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 2000,
        'status'           => $initialStatus,
        'payment_method'   => 'paystack',
    ]);

    $item = OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $vendor->id,
        'quantity'   => 3,
        'unit_price' => 2000,
    ]);

    return compact('owner', 'vendor', 'order', 'product', 'item');
}

function actAsStockEffectsVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('changing status to shipped via the orders list deducts stock and clears the reservation', function () {
    $data = setUpStockEffectsFixture('paid');
    actAsStockEffectsVendor($data);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $data['order']->id, 'shipped');

    $product = $data['product']->fresh();

    expect($product->stock_quantity)->toBe(7)
        ->and($product->reserved_stock)->toBe(0)
        ->and(InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'dispatched')->count())->toBe(1);
});

test('changing status to cancelled via the orders list releases the reservation without touching physical stock', function () {
    $data = setUpStockEffectsFixture('paid');
    actAsStockEffectsVendor($data);

    Livewire::test(ListOrders::class)->call('updateOrderStatus', $data['order']->id, 'cancelled');

    $product = $data['product']->fresh();

    expect($product->stock_quantity)->toBe(10)
        ->and($product->reserved_stock)->toBe(0)
        ->and(InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'reservation_released')->count())->toBe(1);
});

test('changing status to shipped via the order detail page deducts stock exactly once (no double-dispatch)', function () {
    $data = setUpStockEffectsFixture('paid');
    actAsStockEffectsVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $product = $data['product']->fresh();

    expect($product->stock_quantity)->toBe(7)
        ->and($product->reserved_stock)->toBe(0)
        ->and(InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'dispatched')->count())->toBe(1);
});

test('changing status to shipped via order items also deducts stock', function () {
    $data = setUpStockEffectsFixture('paid');
    actAsStockEffectsVendor($data);

    Livewire::test(ViewOrderItem::class, ['record' => $data['item']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $product = $data['product']->fresh();

    expect($product->stock_quantity)->toBe(7)
        ->and($product->reserved_stock)->toBe(0)
        ->and(InventoryLedger::where('product_id', $product->id)->where('transaction_type', 'dispatched')->count())->toBe(1);
});
