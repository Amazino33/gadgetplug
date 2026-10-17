<?php

namespace Tests;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;
use Tests\Support\OfflineHttpFactory;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real external service. Unknown hosts throw;
        // Meta, WhatsApp, Termii and Paystack get harmless defaults that a
        // test's own Http::fake() still overrides. See OfflineHttpFactory.
        $this->app->instance(Factory::class, new OfflineHttpFactory($this->app->make(Dispatcher::class)));
        Http::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();
    }

    protected function skipUnlessFortifyFeature(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
