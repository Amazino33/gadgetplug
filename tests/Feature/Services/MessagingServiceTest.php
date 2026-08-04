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
use App\Services\Messaging\PhoneNumber;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function setUpMessagingVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Messaging Test Store']);
    $category = Category::create(['name' => 'Messaging Category']);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Messaging Product',
        'price' => 10000,
        'stock_quantity' => 5,
        'status' => 'published',
    ]);

    $order = Order::create([
        'reference' => 'ORD-MSG-'.uniqid(),
        'customer_name' => 'Jane Customer',
        'customer_email' => 'jane@example.com',
        'customer_phone' => '08040000000',
        'shipping_address' => '1 Test Street, Lagos',
        'total_amount' => 10000,
        'status' => 'shipped',
        'payment_method' => 'paystack',
    ]);

    return compact('owner', 'vendor', 'order');
}

function makeDeliveryMessage(Vendor $vendor, Order $order, string $channel, string $body = 'Test message body'): DeliveryMessage
{
    return DeliveryMessage::create([
        'vendor_id' => $vendor->id,
        'order_id' => $order->id,
        'recipient_type' => 'customer',
        'channel' => $channel,
        'to_number' => '08040000000',
        'body' => $body,
        'status' => 'queued',
    ]);
}

// --- PhoneNumber --------------------------------------------------------

test('PhoneNumber normalizes local Nigerian formats to the international digits-only shape', function () {
    expect(PhoneNumber::toNigerianInternational('08012345678'))->toBe('2348012345678')
        ->and(PhoneNumber::toNigerianInternational('0801 234 5678'))->toBe('2348012345678')
        ->and(PhoneNumber::toNigerianInternational('8012345678'))->toBe('2348012345678')
        ->and(PhoneNumber::toNigerianInternational('2348012345678'))->toBe('2348012345678')
        ->and(PhoneNumber::toNigerianInternational('+234 801 234 5678'))->toBe('2348012345678')
        ->and(PhoneNumber::toNigerianInternational(null))->toBeNull()
        ->and(PhoneNumber::toNigerianInternational(''))->toBe('');
});

// --- TemplateRenderer -------------------------------------------------

test('template renderer substitutes all known placeholders', function () {
    $renderer = new TemplateRenderer;

    $rendered = $renderer->render(
        'Hi {{customer_name}}, order {{order_number}} totalling {{total}} is {{status}}. Rider: {{rider_name}} ({{rider_phone}}) from {{company_name}}, delivering to {{delivery_address}}.',
        [
            'customer_name' => 'Jane',
            'order_number' => 'ORD-1',
            'total' => '₦10,000.00',
            'status' => 'shipped',
            'rider_name' => 'John',
            'rider_phone' => '0802000000',
            'company_name' => 'Speedy Dispatch',
            'delivery_address' => '1 Test Street',
        ],
    );

    expect($rendered)->toBe(
        'Hi Jane, order ORD-1 totalling ₦10,000.00 is shipped. Rider: John (0802000000) from Speedy Dispatch, delivering to 1 Test Street.'
    );
});

test('template renderer leaves a placeholder untouched when its value is missing from context', function () {
    $renderer = new TemplateRenderer;

    $rendered = $renderer->render('Hi {{customer_name}}, rider is {{rider_name}}.', [
        'customer_name' => 'Jane',
    ]);

    expect($rendered)->toBe('Hi Jane, rider is {{rider_name}}.');
});

test('contextForOrder builds placeholder values from an order and its assigned rider and company', function () {
    $data = setUpMessagingVendor();
    $company = LogisticsCompany::create(['vendor_id' => $data['vendor']->id, 'name' => 'Speedy Dispatch', 'phone' => '08010000000']);
    $rider = DeliveryPerson::create(['vendor_id' => $data['vendor']->id, 'logistics_company_id' => $company->id, 'name' => 'John Rider', 'phone' => '08020000000']);

    $data['order']->update(['logistics_company_id' => $company->id, 'delivery_person_id' => $rider->id]);
    $data['order']->refresh();

    $context = TemplateRenderer::contextForOrder($data['order']);

    expect($context['customer_name'])->toBe('Jane Customer')
        ->and($context['customer_phone'])->toBe('08040000000')
        ->and($context['order_number'])->toBe($data['order']->reference)
        ->and($context['rider_name'])->toBe('John Rider')
        ->and($context['rider_phone'])->toBe('08020000000')
        ->and($context['company_name'])->toBe('Speedy Dispatch')
        ->and($context['status'])->toBe('shipped')
        ->and($context['total'])->toBe('₦10,000.00')
        ->and($context['delivery_address'])->toBe('1 Test Street, Lagos');
});

test('contextForOrder defaults rider and company fields to empty strings when unassigned', function () {
    $data = setUpMessagingVendor();
    $context = TemplateRenderer::contextForOrder($data['order']);

    expect($context['rider_name'])->toBe('')
        ->and($context['rider_phone'])->toBe('')
        ->and($context['company_name'])->toBe('');
});

// --- MessagingService: driver auto-selection --------------------------

test('sms defaults to the log null driver when no termii key is configured', function () {
    config(['services.termii.api_key' => null, 'services.messaging.sms_driver' => null]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['driver'])->toBe('log_null')
        ->and($result->sent_at)->not->toBeNull();
});

test('whatsapp defaults to the wa link driver when no cloud api credentials are configured', function () {
    config([
        'services.whatsapp_cloud.token' => null,
        'services.whatsapp_cloud.phone_number_id' => null,
        'services.wawp.instance_id' => null,
        'services.wawp.access_token' => null,
        'services.messaging.whatsapp_driver' => null,
    ]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp', 'Your order is on the way!');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('link_generated')
        ->and($result->provider_response['driver'])->toBe('wa_link')
        ->and($result->provider_response['url'])->toContain('https://api.whatsapp.com/send?phone=2348040000000')
        ->and($result->provider_response['url'])->toContain(rawurlencode('Your order is on the way!'));
});

test('sms uses termii automatically when credentials are configured', function () {
    config([
        'services.termii.api_key' => 'test-api-key',
        'services.termii.sender_id' => 'TESTSENDER',
        'services.messaging.sms_driver' => null,
    ]);

    Http::fake([
        'api.ng.termii.com/*' => Http::response([
            'code' => 'ok',
            'balance' => 1047.57,
            'message_id' => '3017544054459083819856413',
            'message' => 'Successfully Sent',
            'user' => 'Test Vendor',
            'message_id_str' => '3017544054459083819856413',
        ], 200),
    ]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['message_id'])->toBe('3017544054459083819856413');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.ng.termii.com/api/sms/send'
        && $request['api_key'] === 'test-api-key'
        && $request['from'] === 'TESTSENDER'
        && $request['to'] === '2348040000000');
});

test('whatsapp uses the cloud api automatically when credentials are configured', function () {
    config([
        'services.whatsapp_cloud.token' => 'test-token',
        'services.whatsapp_cloud.phone_number_id' => '1234567890',
        'services.whatsapp_cloud.api_version' => 'v21.0',
        'services.messaging.whatsapp_driver' => null,
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '2348040000000', 'wa_id' => '2348040000000']],
            'messages' => [['id' => 'wamid.TEST123', 'message_status' => 'accepted']],
        ], 200),
    ]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['messages'][0]['id'])->toBe('wamid.TEST123');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/1234567890/messages'
        && $request['messaging_product'] === 'whatsapp'
        && $request['to'] === '2348040000000'
        && $request['type'] === 'text'
        && $request['text']['body'] === 'Test message body');
});

// --- Wawp WhatsApp gateway --------------------------------------------

function configureWawp(array $overrides = []): void
{
    config(array_merge([
        'services.whatsapp_cloud.token' => null,
        'services.whatsapp_cloud.phone_number_id' => null,
        'services.wawp.instance_id' => 'TESTINSTANCE01',
        'services.wawp.access_token' => 'test-access-token',
        'services.messaging.whatsapp_driver' => null,
    ], $overrides));
}

test('whatsapp uses wawp automatically when its credentials are configured and cloud api is not', function () {
    configureWawp();

    Http::fake([
        'api.wawp.net/*' => Http::response([
            'id' => ['_serialized' => 'true_2348040000000@c.us_ABC123'],
            'body' => 'Test message body',
            'to' => '2348040000000@c.us',
            'ack' => 0,
        ], 200),
    ]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['id']['_serialized'])->toBe('true_2348040000000@c.us_ABC123')
        ->and($result->sent_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.wawp.net/v2/send/text'
        && $request->method() === 'POST'
        && $request['instance_id'] === 'TESTINSTANCE01'
        && $request['access_token'] === 'test-access-token'
        && $request['chatId'] === '2348040000000@c.us'
        && $request['message'] === 'Test message body');
});

test('wawp does not leak the access token into the request url', function () {
    configureWawp();
    Http::fake(['api.wawp.net/*' => Http::response(['result' => true], 200)]);

    $data = setUpMessagingVendor();
    app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'test-access-token'));
});

test('cloud api still wins over wawp when both are configured', function () {
    configureWawp([
        'services.whatsapp_cloud.token' => 'test-token',
        'services.whatsapp_cloud.phone_number_id' => '1234567890',
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CLOUD']]], 200),
    ]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['messages'][0]['id'])->toBe('wamid.CLOUD');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'wawp.net'));
});

test('wawp falls back to the wa link driver when its credentials are absent', function () {
    configureWawp(['services.wawp.instance_id' => null, 'services.wawp.access_token' => null]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('link_generated')
        ->and($result->provider_response['driver'])->toBe('wa_link');
});

test('a wawp session error is recorded as failed rather than throwing', function () {
    configureWawp();

    Http::fake([
        'api.wawp.net/*' => Http::response([
            'code' => 'invalid_session',
            'message' => 'Session not found. Please verify your instance_id or session_name.',
        ], 404),
    ]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('failed')
        ->and($result->provider_response['error'])->toBeString()
        ->and($result->sent_at)->toBeNull();
});

// Session-backed gateways report upstream WhatsApp problems in the body while
// still answering 200, so a bare status-code check would mark these as sent.
test('a wawp error body returned under http 200 is still recorded as failed', function () {
    configureWawp();

    Http::fake([
        'api.wawp.net/*' => Http::response(['code' => 'invalid_session', 'message' => 'Session closed'], 200),
    ]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('failed')
        ->and($result->provider_response['body']['code'])->toBe('invalid_session')
        ->and($result->sent_at)->toBeNull();
});

test('a wawp result false envelope is recorded as failed', function () {
    configureWawp();

    Http::fake(['api.wawp.net/*' => Http::response(['result' => false], 200)]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('failed');
});

test('an empty wawp 200 body is treated as failed rather than assumed sent', function () {
    configureWawp();

    Http::fake(['api.wawp.net/*' => Http::response('', 200)]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    expect($result->status)->toBe('failed')
        ->and($result->sent_at)->toBeNull();
});

// --- Test-mode redirect safety valve ----------------------------------

test('the redirect valve diverts the send to the test number while the row keeps the real recipient', function () {
    configureWawp();
    config(['services.messaging.redirect_all_to' => '08136310313']);

    Http::fake(['api.wawp.net/*' => Http::response(['result' => true], 200)]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    // Sent to the test phone...
    Http::assertSent(fn ($request) => $request['chatId'] === '2348136310313@c.us'
        && str_contains($request['message'], '[TEST — intended for 2348040000000]')
        && str_contains($request['message'], 'Test message body'));

    // ...but the order history still records who it was actually for.
    expect($result->status)->toBe('sent')
        ->and($result->to_number)->toBe('2348040000000')
        ->and($result->body)->toBe('Test message body')
        ->and($result->provider_response['redirected_to'])->toBe('2348136310313');
});

test('the redirect valve accepts a local-format number', function () {
    configureWawp();
    config(['services.messaging.redirect_all_to' => '0813 631 0313']);

    Http::fake(['api.wawp.net/*' => Http::response(['result' => true], 200)]);

    $data = setUpMessagingVendor();
    app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    Http::assertSent(fn ($request) => $request['chatId'] === '2348136310313@c.us');
});

test('a blank redirect valve leaves the real recipient untouched', function () {
    configureWawp();
    config(['services.messaging.redirect_all_to' => '']);

    Http::fake(['api.wawp.net/*' => Http::response(['result' => true], 200)]);

    $data = setUpMessagingVendor();
    $result = app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'whatsapp'));

    Http::assertSent(fn ($request) => $request['chatId'] === '2348040000000@c.us'
        && $request['message'] === 'Test message body');

    expect($result->provider_response)->not->toHaveKey('redirected_to');
});

test('the redirect valve applies to sms as well as whatsapp', function () {
    config([
        'services.termii.api_key' => 'test-api-key',
        'services.termii.sender_id' => 'TESTSENDER',
        'services.messaging.sms_driver' => null,
        'services.messaging.redirect_all_to' => '08136310313',
    ]);

    Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'x'], 200)]);

    $data = setUpMessagingVendor();
    app(MessagingService::class)->send(makeDeliveryMessage($data['vendor'], $data['order'], 'sms'));

    Http::assertSent(fn ($request) => $request['to'] === '2348136310313');
});

test('an explicit driver override wins over auto-detection', function () {
    // Termii credentials ARE present, but sms_driver is explicitly forced to log_null.
    config([
        'services.termii.api_key' => 'test-api-key',
        'services.termii.sender_id' => 'TESTSENDER',
        'services.messaging.sms_driver' => 'log_null',
    ]);

    Http::fake();

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('sent')
        ->and($result->provider_response['driver'])->toBe('log_null');

    Http::assertNothingSent();
});

test('a provider failure is recorded as a failed status without throwing', function () {
    config([
        'services.termii.api_key' => 'test-api-key',
        'services.termii.sender_id' => 'TESTSENDER',
        'services.messaging.sms_driver' => null,
    ]);

    Http::fake([
        'api.ng.termii.com/*' => Http::response(['message' => 'Invalid API key'], 401),
    ]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    $result = app(MessagingService::class)->send($message);

    expect($result->status)->toBe('failed')
        ->and($result->provider_response['error'])->toBeString()
        ->and($result->sent_at)->toBeNull();
});

test('sending persists the normalized to_number back onto the delivery message row', function () {
    config(['services.termii.api_key' => null, 'services.messaging.sms_driver' => null]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    expect($message->to_number)->toBe('08040000000');

    $result = app(MessagingService::class)->send($message);

    expect($result->to_number)->toBe('2348040000000')
        ->and($message->fresh()->to_number)->toBe('2348040000000');
});

test('sending updates the same delivery_messages row rather than creating a new one', function () {
    config(['services.termii.api_key' => null, 'services.messaging.sms_driver' => null]);

    $data = setUpMessagingVendor();
    $message = makeDeliveryMessage($data['vendor'], $data['order'], 'sms');

    app(MessagingService::class)->send($message);

    expect(DeliveryMessage::count())->toBe(1)
        ->and(DeliveryMessage::first()->id)->toBe($message->id)
        ->and(DeliveryMessage::first()->status)->toBe('sent');
});
