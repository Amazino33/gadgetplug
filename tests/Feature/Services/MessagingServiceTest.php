<?php

use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\DeliveryPerson;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function setUpMessagingVendor(): array
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Messaging Test Store']);
    $category = Category::create(['name' => 'Messaging Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Messaging Product',
        'price'          => 10000,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $order = Order::create([
        'reference'        => 'ORD-MSG-' . uniqid(),
        'customer_name'    => 'Jane Customer',
        'customer_email'   => 'jane@example.com',
        'customer_phone'   => '08040000000',
        'shipping_address' => '1 Test Street, Lagos',
        'total_amount'     => 10000,
        'status'           => 'shipped',
        'payment_method'   => 'paystack',
    ]);

    return compact('owner', 'vendor', 'order');
}

function makeDeliveryMessage(Vendor $vendor, Order $order, string $channel, string $body = 'Test message body'): DeliveryMessage
{
    return DeliveryMessage::create([
        'vendor_id'      => $vendor->id,
        'order_id'       => $order->id,
        'recipient_type' => 'customer',
        'channel'        => $channel,
        'to_number'      => '08040000000',
        'body'           => $body,
        'status'         => 'queued',
    ]);
}

// --- TemplateRenderer -------------------------------------------------

test('template renderer substitutes all known placeholders', function () {
    $renderer = new TemplateRenderer();

    $rendered = $renderer->render(
        'Hi {{customer_name}}, order {{order_number}} totalling {{total}} is {{status}}. Rider: {{rider_name}} ({{rider_phone}}) from {{company_name}}, delivering to {{delivery_address}}.',
        [
            'customer_name'    => 'Jane',
            'order_number'     => 'ORD-1',
            'total'            => '₦10,000.00',
            'status'           => 'shipped',
            'rider_name'       => 'John',
            'rider_phone'      => '0802000000',
            'company_name'     => 'Speedy Dispatch',
            'delivery_address' => '1 Test Street',
        ],
    );

    expect($rendered)->toBe(
        'Hi Jane, order ORD-1 totalling ₦10,000.00 is shipped. Rider: John (0802000000) from Speedy Dispatch, delivering to 1 Test Street.'
    );
});

test('template renderer leaves a placeholder untouched when its value is missing from context', function () {
    $renderer = new TemplateRenderer();

    $rendered = $renderer->render('Hi {{customer_name}}, rider is {{rider_name}}.', [
        'customer_name' => 'Jane',
    ]);

    expect($rendered)->toBe('Hi Jane, rider is {{rider_name}}.');
});

test('contextForOrder builds placeholder values from an order and its assigned rider and company', function () {
    $data    = setUpMessagingVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider   = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    $data['order']->update(['logistics_company_id' => $company->id, 'delivery_person_id' => $rider->id]);
    $data['order']->refresh();

    $context = TemplateRenderer::contextForOrder($data['order']);

    expect($context['customer_name'])->toBe('Jane Customer')
        ->and($context['order_number'])->toBe($data['order']->reference)
        ->and($context['rider_name'])->toBe('John Rider')
        ->and($context['rider_phone'])->toBe('08020000000')
        ->and($context['company_name'])->toBe('Speedy Dispatch')
        ->and($context['status'])->toBe('shipped')
        ->and($context['total'])->toBe('₦10,000.00')
        ->and($context['delivery_address'])->toBe('1 Test Street, Lagos');
});

test('contextForOrder defaults rider and company fields to empty strings when unassigned', function () {
    $data    = setUpMessagingVendor();
    $context = TemplateRenderer::contextForOrder($data['order']);

    expect($context['rider_name'])->toBe('')
        ->and($context['rider_phone'])->toBe('')
        ->and($context['company_name'])->toBe('');
});

// --- MessagingService: driver auto-selection --------------------------

test('sms defaults to the log null driver when no termii key is configured', function () {
    config(['services.termii.api_key' => null, 'services.messaging.sms_driver' => null]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['driver'])->toBe('log_null')
        ->and($result->sent_at)->not->toBeNull();
});

test('whatsapp defaults to the wa link driver when no cloud api credentials are configured', function () {
    config([
        'services.whatsapp_cloud.token'           => null,
        'services.whatsapp_cloud.phone_number_id' => null,
        'services.messaging.whatsapp_driver'      => null,
    ]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp', 'Your order is on the way!');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('link_generated')
        ->and($result->provider_response['driver'])->toBe('wa_link')
        ->and($result->provider_response['url'])->toContain('https://api.whatsapp.com/send?phone=08040000000')
        ->and($result->provider_response['url'])->toContain(rawurlencode('Your order is on the way!'));
});

test('sms uses termii automatically when credentials are configured', function () {
    config([
        'services.termii.api_key'        => 'test-api-key',
        'services.termii.sender_id'      => 'TESTSENDER',
        'services.messaging.sms_driver'  => null,
    ]);

    Http::fake([
        'api.ng.termii.com/*' => Http::response([
            'code'           => 'ok',
            'balance'        => 1047.57,
            'message_id'     => '3017544054459083819856413',
            'message'        => 'Successfully Sent',
            'user'           => 'Test Vendor',
            'message_id_str' => '3017544054459083819856413',
        ], 200),
    ]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['message_id'])->toBe('3017544054459083819856413');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.ng.termii.com/api/sms/send'
        && $request['api_key'] === 'test-api-key'
        && $request['from'] === 'TESTSENDER'
        && $request['to'] === '08040000000');
});

test('whatsapp uses the cloud api automatically when credentials are configured', function () {
    config([
        'services.whatsapp_cloud.token'           => 'test-token',
        'services.whatsapp_cloud.phone_number_id' => '1234567890',
        'services.whatsapp_cloud.api_version'     => 'v21.0',
        'services.messaging.whatsapp_driver'      => null,
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts'           => [['input' => '2348040000000', 'wa_id' => '2348040000000']],
            'messages'           => [['id' => 'wamid.TEST123', 'message_status' => 'accepted']],
        ], 200),
    ]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['messages'][0]['id'])->toBe('wamid.TEST123');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/1234567890/messages'
        && $request['messaging_product'] === 'whatsapp'
        && $request['type'] === 'text'
        && $request['text']['body'] === 'Test message body');
});

test('an explicit driver override wins over auto-detection', function () {
    // Termii credentials ARE present, but sms_driver is explicitly forced to log_null.
    config([
        'services.termii.api_key'       => 'test-api-key',
        'services.termii.sender_id'     => 'TESTSENDER',
        'services.messaging.sms_driver' => 'log_null',
    ]);

    Http::fake();

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['driver'])->toBe('log_null');

    Http::assertNothingSent();
});

test('a provider failure is recorded as a failed status without throwing', function () {
    config([
        'services.termii.api_key'       => 'test-api-key',
        'services.termii.sender_id'     => 'TESTSENDER',
        'services.messaging.sms_driver' => null,
    ]);

    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'Invalid API key'], 401),
    ]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('failed')
        ->and($result->provider_response['error'])->toBeString()
        ->and($result->sent_at)->toBeNull();
});

test('sending updates the same delivery_messages row rather than creating a new one', function () {
    config(['services.termii.api_key' => null, 'services.messaging.sms_driver' => null]);

    $data    = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    app(MessagingService::class)->send($message);

    expect(DeliveryMessage::count())->toBe(1)
        ->and(DeliveryMessage::first()->id)->toBe($message->id)
        ->and(DeliveryMessage::first()->status)->toBe('sent');
});
