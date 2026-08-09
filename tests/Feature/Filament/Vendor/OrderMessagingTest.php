<?php

use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Filament\Vendor\Resources\Orders\RelationManagers\DeliveryMessagesRelationManager;
use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
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

function setUpMessagingOrderVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Order Messaging Store', 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Order Messaging Category']);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Order Messaging Product',
        'price' => 8000,
        'stock_quantity' => 10,
        'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-MSGUI-'.uniqid(),
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street, Lagos',
        'total_amount' => 8000,
        'status' => 'shipped',
        'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'quantity' => 1,
        'unit_price' => 8000,
    ]);

    return compact('owner', 'vendor', 'order');
}

function actAsMessagingVendor(array $data): void
{
    test()->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($data['vendor']);
}

test('sending a message to the customer creates a delivery message and sends it', function () {
    config(['services.messaging.whatsapp_driver' => 'log_null']);

    $data = setUpMessagingOrderVendor();
    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData([
            'recipient_type' => 'customer',
            'channel' => 'whatsapp',
            'body' => 'Your order is on the way!',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $data['order']->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->recipient_type)->toBe('customer')
        ->and($message->to_number)->toBe('2348040000000')
        ->and($message->body)->toBe('Your order is on the way!')
        ->and($message->status)->toBe('sent')
        ->and($message->sent_by)->toBe($data['owner']->id);
});

test('choosing a template prefills the channel and body via the template picker', function () {
    $data = setUpMessagingOrderVendor();
    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'body' => 'Hi {{customer_name}}, order {{order_number}} is on its way!',
    ]);

    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData(['recipient_type' => 'customer', 'template_key' => 'customer_dispatched'])
        ->assertActionDataSet([
            'channel' => 'whatsapp',
            'body' => 'Hi Jane Customer, order '.$data['order']->reference.' is on its way!',
        ]);
});

test('the rider option is unavailable on the send-message form when no rider is assigned', function () {
    $data = setUpMessagingOrderVendor();
    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData([
            'recipient_type' => 'rider',
            'channel' => 'whatsapp',
            'body' => 'You have a delivery.',
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['recipient_type']);

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse();
});

test('sending a message to a rider with no phone number on file fails gracefully without creating a log entry', function () {
    $data = setUpMessagingOrderVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'No Phone Rider', 'phone' => '']);

    $data['order']->update(['logistics_company_id' => $company->id, 'delivery_person_id' => $rider->id]);

    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData([
            'recipient_type' => 'rider',
            'channel' => 'whatsapp',
            'body' => 'You have a delivery.',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse();
});

test('assigning a rider with notify enabled sends the rider_assignment template automatically', function () {
    config(['services.messaging.whatsapp_driver' => 'log_null']);

    $data = setUpMessagingOrderVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'rider_assignment',
        'recipient_type' => 'rider',
        'channel' => 'whatsapp',
        'body' => 'Hi {{rider_name}}, deliver order {{order_number}} to {{delivery_address}}.',
    ]);

    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData([
            'logistics_company_id' => $company->id,
            'delivery_person_id' => $rider->id,
            'notify_rider' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $data['order']->id)->where('recipient_type', 'rider')->first();

    expect($message)->not->toBeNull()
        ->and($message->to_number)->toBe('2348020000000')
        ->and($message->body)->toBe('Hi John Rider, deliver order '.$data['order']->reference.' to 1 Test Street, Lagos.')
        ->and($message->status)->toBe('sent');
});

test('assigning a rider with notify disabled does not send any message', function () {
    $data = setUpMessagingOrderVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    MessageTemplate::create([
        'vendor_id' => $data['vendor']->id,
        'key' => 'rider_assignment',
        'recipient_type' => 'rider',
        'channel' => 'whatsapp',
        'body' => 'Hi {{rider_name}}, delivery assigned.',
    ]);

    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData([
            'logistics_company_id' => $company->id,
            'delivery_person_id' => $rider->id,
            'notify_rider' => false,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse()
        ->and($data['order']->fresh()->delivery_person_id)->toBe($rider->id);
});

test('assigning a rider with notify enabled but no active rider_assignment template skips gracefully', function () {
    $data = setUpMessagingOrderVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('assignLogistics')
        ->setActionData([
            'logistics_company_id' => $company->id,
            'delivery_person_id' => $rider->id,
            'notify_rider' => true,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DeliveryMessage::where('order_id', $data['order']->id)->exists())->toBeFalse()
        ->and($data['order']->fresh()->delivery_person_id)->toBe($rider->id);
});

test('the delivery messages log shows entries for the order and supports resend', function () {
    config(['services.messaging.whatsapp_driver' => 'log_null']);

    $data = setUpMessagingOrderVendor();
    $message = DeliveryMessage::create([
        'vendor_id' => $data['vendor']->id,
        'order_id' => $data['order']->id,
        'recipient_type' => 'customer',
        'channel' => 'whatsapp',
        'to_number' => '08040000000',
        'body' => 'Original message',
        'status' => 'failed',
        'provider_response' => ['error' => 'timeout'],
    ]);

    actAsMessagingVendor($data);

    Livewire::test(DeliveryMessagesRelationManager::class, [
        'ownerRecord' => $data['order'],
        'pageClass' => ViewOrder::class,
    ])
        ->assertSee('Original message')
        ->callTableAction('resend', $message);

    expect($message->fresh()->status)->toBe('sent');
});

test('sending a whatsapp message with no cloud api credentials configured falls back to the wa_link notice without erroring', function () {
    // Force real auto-detection (no log_null override) so this actually exercises
    // the wa_link fallback path — this is the default state for any fresh install
    // with no WhatsApp Cloud credentials configured, and must never crash the page.
    config([
        'services.messaging.whatsapp_driver' => null,
        'services.whatsapp_cloud.token' => null,
        'services.whatsapp_cloud.phone_number_id' => null,
    ]);

    $data = setUpMessagingOrderVendor();
    actAsMessagingVendor($data);

    Livewire::test(ViewOrder::class, ['record' => $data['order']->getRouteKey()])
        ->mountAction('sendMessage')
        ->setActionData([
            'recipient_type' => 'customer',
            'channel' => 'whatsapp',
            'body' => 'Your order is on the way!',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $message = DeliveryMessage::where('order_id', $data['order']->id)->first();

    expect($message)->not->toBeNull()
        ->and($message->status)->toBe('link_generated')
        ->and($message->provider_response['url'] ?? null)->toContain('https://api.whatsapp.com/send');
});
