<?php

namespace App\Services\Meta;

use App\Jobs\SendMetaConversionEventJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Builds and sends Meta Conversions API event payloads. Payload construction
// (hashing user_data) is synchronous and cheap — only the actual HTTP POST to
// Meta is meant to run inside a queued job (SendMetaConversionEventJob), so a
// slow/unreachable Meta endpoint never blocks checkout. This class doesn't
// care which caller is synchronous vs. queued; it just builds a payload and,
// separately, can send one.
class MetaConversionsService
{
    public function __construct(private MetaEventHasher $hasher) {}

    /**
     * @param array{
     *     email?: ?string, phone?: ?string, name?: ?string, city?: ?string,
     *     fbp?: ?string, fbc?: ?string, client_ip?: ?string, user_agent?: ?string,
     * } $userData Raw (unhashed) user data — em/ph/fn/ln/ct are hashed here;
     *   fbp, fbc, client IP, and user agent are sent as-is, per Meta's spec.
     * @param array<string, mixed> $customData e.g. ['currency' => 'NGN', 'value' => 1000.0, 'content_ids' => [1], 'content_type' => 'product']
     */
    public function buildEventPayload(
        string $eventName,
        string $eventId,
        string $eventSourceUrl,
        array $userData = [],
        array $customData = [],
    ): array {
        $hashedUserData = array_filter([
            'em' => $this->wrap($this->hasher->hashEmail($userData['email'] ?? null)),
            'ph' => $this->wrap($this->hasher->hashPhone($userData['phone'] ?? null)),
            'fn' => $this->wrap($this->hasher->hashFirstName($userData['name'] ?? null)),
            'ln' => $this->wrap($this->hasher->hashLastName($userData['name'] ?? null)),
            'ct' => $this->wrap($this->hasher->hashCity($userData['city'] ?? null)),
        ]);

        // Not hashed — these are Meta's own opaque browser identifiers and
        // request metadata, not customer PII, and Meta's matching needs them
        // in the clear.
        $rawUserData = array_filter([
            'fbp'                => $userData['fbp'] ?? null,
            'fbc'                => $userData['fbc'] ?? null,
            'client_ip_address'  => $userData['client_ip'] ?? null,
            'client_user_agent'  => $userData['user_agent'] ?? null,
        ]);

        $event = [
            'event_name'       => $eventName,
            'event_time'       => now()->timestamp,
            'event_id'         => $eventId,
            'event_source_url' => $eventSourceUrl,
            'action_source'    => 'website',
            'user_data'        => $hashedUserData + $rawUserData,
        ];

        if ($customData !== []) {
            $event['custom_data'] = $customData;
        }

        $payload = ['data' => [$event]];

        $testEventCode = config('services.meta.test_event_code');

        if (filled($testEventCode)) {
            $payload['test_event_code'] = $testEventCode;
        }

        return $payload;
    }

    /**
     * Builds a payload and queues its delivery in one call — the shape every
     * trigger point (product page, cart, checkout, order observer) actually
     * wants; buildEventPayload/send stay available separately for testing
     * and for the one caller (checkout's success screen) that also needs the
     * built event_id to render a matching browser-side fbq() call.
     *
     * @param array<string, mixed> $userData see buildEventPayload
     * @param array<string, mixed> $customData see buildEventPayload
     */
    public function dispatchEvent(
        string $eventName,
        string $eventId,
        string $eventSourceUrl,
        array $userData = [],
        array $customData = [],
    ): void {
        $payload = $this->buildEventPayload($eventName, $eventId, $eventSourceUrl, $userData, $customData);

        SendMetaConversionEventJob::dispatch($payload);
    }

    /**
     * Sends an already-built payload (from buildEventPayload) to Meta.
     * Throws on failure — the caller (SendMetaConversionEventJob) is what
     * turns that into a retry.
     *
     * Wire format follows Meta's own documented shape exactly: access_token
     * as a query parameter (not a body field), `data` as a JSON-encoded
     * string form field (not a raw JSON body) — confirmed against Meta's
     * Conversions API docs, not assumed.
     */
    public function send(array $payload): void
    {
        $pixelId = config('services.meta.pixel_id');
        $token   = config('services.meta.capi_access_token');
        $version = config('services.meta.graph_version');

        if (blank($pixelId) || blank($token)) {
            Log::error('Meta CAPI event not sent — pixel_id or capi_access_token missing from config.');

            return;
        }

        $form = ['data' => json_encode($payload['data'])];

        if (isset($payload['test_event_code'])) {
            $form['test_event_code'] = $payload['test_event_code'];
        }

        $url = "https://graph.facebook.com/{$version}/{$pixelId}/events?"
            . http_build_query(['access_token' => $token]);

        $response = Http::asForm()->timeout(15)->post($url, $form);

        if (! $response->successful()) {
            Log::error('Meta CAPI event send failed.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            $response->throw();
        }
    }

    /**
     * @return array<int, string>|null
     */
    private function wrap(?string $hashed): ?array
    {
        return $hashed === null ? null : [$hashed];
    }
}
