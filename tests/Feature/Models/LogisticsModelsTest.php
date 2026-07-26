<?php

use App\Models\DeliveryMessage;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function setUpLogisticsVendor(): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Logistics Test Store']);

    return compact('owner', 'vendor');
}

test('logistics company belongs to its vendor and can list its riders', function () {
    $data    = setUpLogisticsVendor();
    $company = LogisticsCompany::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Speedy Dispatch',
        'phone'     => '08010000000',
    ]);

    $rider = DeliveryPerson::create([
        'vendor_id'            => $data['vendor']->id,
        'logistics_company_id' => $company->id,
        'name'                 => 'John Rider',
        'phone'                => '08020000000',
    ]);

    expect($company->vendor->is($data['vendor']))->toBeTrue()
        ->and($company->deliveryPersons)->toHaveCount(1)
        ->and($company->deliveryPersons->first()->is($rider))->toBeTrue()
        ->and($company->refresh()->is_active)->toBeTrue();
});

test('delivery person can exist without a logistics company (freelance rider)', function () {
    $data  = setUpLogisticsVendor();
    $rider = DeliveryPerson::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Freelance Femi',
        'phone'     => '08030000000',
    ]);

    expect($rider->logistics_company_id)->toBeNull()
        ->and($rider->logisticsCompany)->toBeNull()
        ->and($rider->vendor->is($data['vendor']))->toBeTrue();
});

test('order can be assigned a logistics company and delivery person', function () {
    $data    = setUpLogisticsVendor();
    $company = LogisticsCompany::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Speedy Dispatch',
        'phone'     => '08010000000',
    ]);
    $rider = DeliveryPerson::create([
        'vendor_id'            => $data['vendor']->id,
        'logistics_company_id' => $company->id,
        'name'                 => 'John Rider',
        'phone'                => '08020000000',
    ]);

    $order = Order::create([
        'reference'      => 'ORD-TEST-1',
        'customer_name'  => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'   => 5000,
        'status'         => 'paid',
        'payment_method' => 'paystack',
    ]);

    $order->update([
        'logistics_company_id' => $company->id,
        'delivery_person_id'   => $rider->id,
    ]);

    $order->refresh();

    expect($order->logisticsCompany->is($company))->toBeTrue()
        ->and($order->deliveryPerson->is($rider))->toBeTrue()
        ->and($company->orders->first()->is($order))->toBeTrue()
        ->and($rider->orders->first()->is($order))->toBeTrue();
});

test('assigning logistics to an order writes an activity log stamped with the correct vendor_id', function () {
    $data     = setUpLogisticsVendor();
    $category = \App\Models\Category::create(['name' => 'Test Category']);
    $product  = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $category->id,
        'name'           => 'Test Product',
        'price'          => 1000,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'         => 'ORD-TEST-2',
        'customer_name'     => 'Jane Customer',
        'customer_email'    => 'jane@example.com',
        'customer_phone'    => '08040000000',
        'shipping_address'  => '1 Test Street',
        'total_amount'      => 1000,
        'status'            => 'paid',
        'payment_method'    => 'paystack',
    ]);

    OrderItem::create([
        'order_id'   => $order->id,
        'product_id' => $product->id,
        'vendor_id'  => $data['vendor']->id,
        'quantity'   => 1,
        'unit_price' => 1000,
    ]);

    $company = LogisticsCompany::create([
        'vendor_id' => $data['vendor']->id,
        'name'      => 'Speedy Dispatch',
        'phone'     => '08010000000',
    ]);

    $order->update(['logistics_company_id' => $company->id]);

    $activity = Activity::latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->vendor_id)->toBe($data['vendor']->id)
        ->and($activity->changes()['attributes']['logistics_company_id'] ?? null)->toBe($company->id);
});

test('delivery message belongs to its order, vendor, and optional sender', function () {
    $data  = setUpLogisticsVendor();
    $order = Order::create([
        'reference'        => 'ORD-TEST-3',
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 1000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    $message = DeliveryMessage::create([
        'vendor_id'      => $data['vendor']->id,
        'order_id'       => $order->id,
        'recipient_type' => 'customer',
        'channel'        => 'whatsapp',
        'to_number'      => '08040000000',
        'body'           => 'Your order has been dispatched.',
        'status'         => 'sent',
        'sent_by'        => $data['owner']->id,
        'sent_at'        => now(),
    ]);

    expect($message->order->is($order))->toBeTrue()
        ->and($message->vendor->is($data['vendor']))->toBeTrue()
        ->and($message->sentBy->is($data['owner']))->toBeTrue()
        ->and($order->deliveryMessages->first()->is($message))->toBeTrue()
        ->and($message->sent_at)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
});

test('delivery message can be created without a sender for automated sends', function () {
    $data  = setUpLogisticsVendor();
    $order = Order::create([
        'reference'        => 'ORD-TEST-4',
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street',
        'total_amount'     => 1000,
        'status'           => 'paid',
        'payment_method'   => 'paystack',
    ]);

    $message = DeliveryMessage::create([
        'vendor_id'      => $data['vendor']->id,
        'order_id'       => $order->id,
        'recipient_type' => 'rider',
        'channel'        => 'sms',
        'to_number'      => '08020000000',
        'body'           => 'You have a new delivery.',
        'status'         => 'queued',
    ]);

    expect($message->sent_by)->toBeNull()
        ->and($message->sentBy)->toBeNull()
        ->and($message->sent_at)->toBeNull();
});

test('message template belongs to its vendor and enforces a unique key per vendor', function () {
    $data     = setUpLogisticsVendor();
    $template = MessageTemplate::create([
        'vendor_id'      => $data['vendor']->id,
        'key'            => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel'        => 'whatsapp',
        'body'           => 'Hi {{customer_name}}, your order {{order_number}} has been dispatched.',
    ]);

    expect($template->vendor->is($data['vendor']))->toBeTrue()
        ->and($template->refresh()->is_active)->toBeTrue();

    expect(fn () => MessageTemplate::create([
        'vendor_id'      => $data['vendor']->id,
        'key'            => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel'        => 'whatsapp',
        'body'           => 'Duplicate key for same vendor.',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('the same template key is reusable across different vendors', function () {
    $data  = setUpLogisticsVendor();
    $data2 = setUpLogisticsVendor();

    MessageTemplate::create([
        'vendor_id'      => $data['vendor']->id,
        'key'            => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel'        => 'whatsapp',
        'body'           => 'Template for vendor 1.',
    ]);

    $second = MessageTemplate::create([
        'vendor_id'      => $data2['vendor']->id,
        'key'            => 'customer_dispatched',
        'recipient_type' => 'customer',
        'channel'        => 'whatsapp',
        'body'           => 'Template for vendor 2.',
    ]);

    expect($second->exists)->toBeTrue();
});
