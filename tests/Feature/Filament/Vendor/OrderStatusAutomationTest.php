<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpAutomationOrderVendor(string $initialStatus = 'paid'): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Automation Test Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Automation Category']);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Automation Product',
        'price' => 6000,
        'stock_quantity' => 10,
        'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-AUTO-'.uniqid(),
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street, Lagos',
        'total_amount' => 6000,
        'status' => $initialStatus,
        'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'quantity' => 1,
        'unit_price' => 6000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsAutomationVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('advancing status to shipped auto-sends the customer_dispatched template', function () {
    $data = setUpAutomationOrderVendor('paid');
    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'body' => 'Hi {{customer_name}}, order {{order_number}} is dispatched.',
    ]);

    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $data['order']->id)->where('recipient_type', 'customer')->first();

    expect($message)->not->toBeNull()
        ->and($message->channel)->toBe('whatsapp')
        ->and($message->to_number)->toBe('2348040000000')
        ->and($message->body)->toBe('Hi Jane Customer, order '.$data['order']->reference.' is dispatched.')
        ->and($message->status)->toBe('sent')
        ->and($message->sent_by)->toBeNull();
});

test('advancing status to delivered auto-sends the customer_delivered template', function () {
    $data = setUpAutomationOrderVendor('shipped');
    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'customer_delivered',
        'recipient_type' => 'customer',
        'channel' => 'sms',
        'body' => 'Hi {{customer_name}}, order {{order_number}} delivered. Thanks!',
    ]);

    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'delivered', 'notify_customer' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $data['order']->id)->where('recipient_type', 'customer')->first();

    expect($message)->not->toBeNull()
        ->and($message->channel)->toBe('sms')
        ->and($message->body)->toBe('Hi Jane Customer, order '.$data['order']->reference.' delivered. Thanks!');
});

test('the notify toggle set to false suppresses the customer message but still updates status', function () {
    $data = setUpAutomationOrderVendor('paid');
    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'body' => 'Hi {{customer_name}}, dispatched.',
    ]);

    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse()
        ->and($data['order']->fresh()->status)->toBe('shipped');
});

test('no message is sent when there is no active matching template for the vendor', function () {
    $data = setUpAutomationOrderVendor('paid');
    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse()
        ->and($data['order']->fresh()->status)->toBe('shipped');
});

test('an inactive template is never used for automatic customer notification', function () {
    $data = setUpAutomationOrderVendor('paid');
    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'body' => 'Hi {{customer_name}}, dispatched.',
        'is_active' => false,
    ]);

    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'shipped', 'notify_customer' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse();
});

test('cancelling an order never sends a customer message regardless of the notify toggle', function () {
    $data = setUpAutomationOrderVendor('paid');
    actAsAutomationVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('updateStatus')
        ->setActionData(['status' => 'cancelled'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse()
        ->and($data['order']->fresh()->status)->toBe('cancelled');
});

test('the original new-order vendor notification still fires on transition into paid', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Notify Regression Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Notify Regression Category']);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Notify Regression Product',
        'price' => 3000,
        'stock_quantity' => 10,
        'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-NOTIFY-'.uniqid(),
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount' => 3000,
        'status' => 'pending',
        'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'quantity' => 1,
        'unit_price' => 3000,
    ]);

    expect($owner->notifications()->count())->toBe(0);

    $order->update(['status' => 'paid']);

    expect($owner->fresh()->notifications()->count())->toBe(1)
        ->and($owner->fresh()->notifications()->first()->data['title'])->toContain($order->reference);
});
