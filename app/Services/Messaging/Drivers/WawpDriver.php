<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Wawp WhatsApp gateway text send — request/response shape confirmed against
// https://api.wawp.net/en/docs/v2/send/text (2026-08-04). Unlike the Meta Cloud
// API this rides an ordinary WhatsApp session (linked by QR), so there is no
// 24-hour customer-service window and no template pre-approval — but the session
// can drop, which surfaces as a 404 `invalid_session` and needs a re-scan in the
// Wawp dashboard rather than a code change.
//
// Sent as a POST JSON body even though the endpoint also accepts GET. Wawp's own
// docs warn against GET here because the access_token then lands in web-server
// access logs and browser history; it also only parses credentials from a JSON
// body — form-encoded POSTs come back 401 "Missing access_token".
class WawpDriver implements MessagingDriver
{
    private const ENDPOINT = 'https://api.wawp.net/v2/send/text';

    public function send(DeliveryMessage $message): DriverSendResult
    {
        $instanceId  = config('services.wawp.instance_id');
        $accessToken = config('services.wawp.access_token');

        if (! $instanceId || ! $accessToken) {
            throw new RuntimeException('WAWP_INSTANCE_ID / WAWP_ACCESS_TOKEN are not configured.');
        }

        $response = Http::timeout(15)
            ->asJson()
            ->post(self::ENDPOINT, [
                'instance_id'  => $instanceId,
                'access_token' => $accessToken,
                'chatId'       => self::toChatId($message->to_number),
                'message'      => $message->body,
            ])
            ->throw()
            ->json();

        // Wawp signals most failures with a 4xx that ->throw() already converted
        // into an exception. The checks below cover the residual case of an error
        // body returned under HTTP 200, which these session-backed gateways do
        // when the upstream WhatsApp session — not the API call — is what failed.
        if (! self::looksSuccessful($response)) {
            return DriverSendResult::failed([
                'driver' => 'wawp',
                'error'  => 'Wawp returned HTTP 200 with a non-success body.',
                'body'   => $response,
            ]);
        }

        return DriverSendResult::sent($response);
    }

    // Recipients are stored as bare digits (2348012345678); Wawp addresses chats
    // by WhatsApp JID. Anything already carrying an @suffix is passed through so
    // a group JID (…@g.us) still works.
    private static function toChatId(string $toNumber): string
    {
        if (str_contains($toNumber, '@')) {
            return $toNumber;
        }

        return preg_replace('/\D/', '', $toNumber).'@c.us';
    }

    private static function looksSuccessful(mixed $response): bool
    {
        // An unparseable or empty 2xx body means we genuinely do not know whether
        // the message went out. Treat that as a failure: a false `failed` costs a
        // duplicate message, while a false `sent` leaves a customer un-notified
        // with nothing in the UI to show it.
        if (! is_array($response) || $response === []) {
            return false;
        }

        // Error envelope: {"code": "invalid_session", "message": "..."}
        if (array_key_exists('code', $response)) {
            return false;
        }

        // Legacy/WP-plugin envelope: {"result": true, "message_id": "..."}
        if (array_key_exists('result', $response)) {
            return (bool) $response['result'];
        }

        // v2 success is the message object itself, keyed by its WhatsApp message id.
        return true;
    }
}
