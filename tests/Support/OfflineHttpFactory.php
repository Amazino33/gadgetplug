<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The HTTP client every test gets: no request ever leaves the machine.
 *
 * Stray requests are refused (preventStrayRequests), and the external services
 * the app talks to in normal flows — Meta CAPI, WhatsApp (Cloud API and Wawp),
 * Termii and Paystack — get a harmless default answer, so a test that merely
 * renders a product page or places an order works with the internet off.
 *
 * The defaults rank BELOW anything a test fakes itself. Laravel tries stubs in
 * the order they were registered, so defaults registered up front would win
 * over a test's own Http::fake(); instead they are appended after the test's
 * stubs on every request. A host not listed here gets no default at all: it
 * throws StrayRequestException until the test fakes it on purpose.
 */
class OfflineHttpFactory extends Factory
{
    public function createPendingRequest()
    {
        return parent::createPendingRequest()
            ->stub(($this->stubCallbacks ?? new Collection)->merge([
                fn (Request $request) => self::defaultResponse($request),
            ]));
    }

    private static function defaultResponse(Request $request): mixed
    {
        $url = $request->url();

        return match (true) {
            // Meta Conversions API.
            Str::is('https://graph.facebook.com/*/events*', $url) => static::response(['events_received' => 1, 'fbtrace_id' => 'test']),

            // WhatsApp Cloud API (same host as CAPI, different path).
            Str::is('https://graph.facebook.com/*/messages*', $url) => static::response(['messages' => [['id' => 'wamid.test']]]),

            Str::is('https://api.wawp.net/*', $url) => static::response(['id' => 'test-wawp-message']),

            Str::contains($url, 'termii.com') => static::response(['message_id' => 'test-termii', 'code' => 'ok']),

            // Paystack: initialize hands back a checkout URL; verify answers
            // "not paid" unless a test fakes success itself, so nothing is
            // ever confirmed by accident.
            Str::is('https://api.paystack.co/transaction/initialize*', $url) => static::response([
                'status' => true,
                'data'   => ['authorization_url' => 'https://checkout.paystack.com/test', 'reference' => 'test'],
            ]),
            Str::is('https://api.paystack.co/transaction/verify/*', $url) => static::response([
                'status' => true,
                'data'   => ['status' => 'abandoned', 'amount' => 0, 'currency' => 'NGN'],
            ]),

            default => null,
        };
    }
}
