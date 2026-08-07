<?php

use App\Jobs\SendMetaConversionEventJob;
use App\Services\Meta\MetaConversionsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the job hands its payload to MetaConversionsService::send unchanged', function () {
    config([
        'services.meta.pixel_id'          => '111222333',
        'services.meta.capi_access_token' => 'job-test-token',
        'services.meta.graph_version'     => 'v26.0',
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
    ]);

    $payload = app(MetaConversionsService::class)->buildEventPayload('Purchase', 'GP-JOB-1', 'https://gadgetplug.com.ng/checkout');

    (new SendMetaConversionEventJob($payload))->handle(app(MetaConversionsService::class));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return json_decode($request['data'], true)[0]['event_id'] === 'GP-JOB-1';
    });
});

test('the job can be dispatched onto the queue without executing synchronously', function () {
    Queue::fake();

    $payload = app(MetaConversionsService::class)->buildEventPayload('Purchase', 'GP-JOB-2', 'https://gadgetplug.com.ng/checkout');

    dispatch(new SendMetaConversionEventJob($payload));

    Queue::assertPushed(SendMetaConversionEventJob::class, function (SendMetaConversionEventJob $job) {
        return true;
    });
});
