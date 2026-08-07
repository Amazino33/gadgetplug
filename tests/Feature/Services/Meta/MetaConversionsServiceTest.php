<?php

use App\Services\Meta\MetaConversionsService;

test('the built payload has the correct top-level event shape', function () {
    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload(
        eventName: 'Purchase',
        eventId: 'GP-TEST12345',
        eventSourceUrl: 'https://gadgetplug.com.ng/checkout',
        userData: ['email' => 'jane@example.com'],
        customData: ['currency' => 'NGN', 'value' => 15000.0, 'content_ids' => [1], 'content_type' => 'product'],
    );

    $event = $payload['data'][0];

    expect($event['event_name'])->toBe('Purchase')
        ->and($event['event_id'])->toBe('GP-TEST12345')
        ->and($event['event_source_url'])->toBe('https://gadgetplug.com.ng/checkout')
        ->and($event['action_source'])->toBe('website')
        ->and($event['event_time'])->toBeInt()
        ->and($event['custom_data'])->toBe(['currency' => 'NGN', 'value' => 15000.0, 'content_ids' => [1], 'content_type' => 'product']);
});

test('user_data fields are hashed and wrapped in arrays per the Meta spec', function () {
    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload(
        eventName: 'ViewContent',
        eventId: 'evt-1',
        eventSourceUrl: 'https://gadgetplug.com.ng/product/x',
        userData: ['email' => 'jane@example.com', 'phone' => '08012345678', 'name' => 'Jane Doe', 'city' => 'Uyo'],
    );

    $userDataOut = $payload['data'][0]['user_data'];

    expect($userDataOut['em'])->toBe([hash('sha256', 'jane@example.com')])
        ->and($userDataOut['ph'])->toBe([hash('sha256', '2348012345678')])
        ->and($userDataOut['fn'])->toBe([hash('sha256', 'jane')])
        ->and($userDataOut['ln'])->toBe([hash('sha256', 'doe')])
        ->and($userDataOut['ct'])->toBe([hash('sha256', 'uyo')]);
});

test('fbp and fbc pass through unhashed, alongside client ip and user agent', function () {
    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload(
        eventName: 'AddToCart',
        eventId: 'evt-2',
        eventSourceUrl: 'https://gadgetplug.com.ng/product/x',
        userData: [
            'fbp'        => 'fb.1.1690000000000.1234567890',
            'fbc'        => 'fb.1.1690000000000.abcXYZ',
            'client_ip'  => '105.112.20.1',
            'user_agent' => 'Mozilla/5.0 Test Agent',
        ],
    );

    $userDataOut = $payload['data'][0]['user_data'];

    expect($userDataOut['fbp'])->toBe('fb.1.1690000000000.1234567890')
        ->and($userDataOut['fbc'])->toBe('fb.1.1690000000000.abcXYZ')
        ->and($userDataOut['client_ip_address'])->toBe('105.112.20.1')
        ->and($userDataOut['client_user_agent'])->toBe('Mozilla/5.0 Test Agent')
        ->and($userDataOut)->not->toHaveKey('em');
});

test('missing user data fields are simply omitted, not sent as empty', function () {
    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload(
        eventName: 'InitiateCheckout',
        eventId: 'evt-3',
        eventSourceUrl: 'https://gadgetplug.com.ng/checkout',
    );

    expect($payload['data'][0]['user_data'])->toBe([]);
});

test('test_event_code is included when configured', function () {
    config(['services.meta.test_event_code' => 'TEST9876']);

    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload('PageView', 'evt-4', 'https://gadgetplug.com.ng/');

    expect($payload['test_event_code'])->toBe('TEST9876');
});

test('test_event_code is absent from the payload when not configured', function () {
    config(['services.meta.test_event_code' => null]);

    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload('PageView', 'evt-5', 'https://gadgetplug.com.ng/');

    expect($payload)->not->toHaveKey('test_event_code');
});

test('custom_data is omitted entirely when none is given', function () {
    $service = app(MetaConversionsService::class);

    $payload = $service->buildEventPayload('PageView', 'evt-6', 'https://gadgetplug.com.ng/');

    expect($payload['data'][0])->not->toHaveKey('custom_data');
});

test('send posts to the correct Meta endpoint with the token as a query param and data as a form-encoded json string', function () {
    config([
        'services.meta.pixel_id'          => '999888777',
        'services.meta.capi_access_token' => 'test-token-abc',
        'services.meta.graph_version'     => 'v26.0',
    ]);

    Illuminate\Support\Facades\Http::fake([
        'graph.facebook.com/*' => Illuminate\Support\Facades\Http::response(['events_received' => 1], 200),
    ]);

    $service = app(MetaConversionsService::class);
    $payload = $service->buildEventPayload('Purchase', 'GP-ABC', 'https://gadgetplug.com.ng/checkout');

    $service->send($payload);

    Illuminate\Support\Facades\Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/999888777/events?access_token=test-token-abc')
            && json_decode($request['data'], true)[0]['event_id'] === 'GP-ABC';
    });
});

test('send does nothing and logs when pixel_id or token is missing', function () {
    config(['services.meta.pixel_id' => null, 'services.meta.capi_access_token' => null]);

    Illuminate\Support\Facades\Http::fake();

    $service = app(MetaConversionsService::class);
    $payload = $service->buildEventPayload('Purchase', 'GP-ABC', 'https://gadgetplug.com.ng/checkout');

    $service->send($payload);

    Illuminate\Support\Facades\Http::assertNothingSent();
});
