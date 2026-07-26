<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// WhatsApp Business Cloud API text message — request/response shape confirmed
// against https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
// (2026-07-26). Meta periodically deprecates old Graph API versions — check
// WHATSAPP_CLOUD_API_VERSION still points to a supported one if sends start failing.
class WhatsAppCloudApiDriver implements MessagingDriver
{
    public function send(DeliveryMessage $message): DriverSendResult
    {
        $token         = config('services.whatsapp_cloud.token');
        $phoneNumberId = config('services.whatsapp_cloud.phone_number_id');

        if (! $token || ! $phoneNumberId) {
            throw new RuntimeException('WHATSAPP_CLOUD_TOKEN / WHATSAPP_CLOUD_PHONE_NUMBER_ID are not configured.');
        }

        $version = config('services.whatsapp_cloud.api_version', 'v21.0');

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => preg_replace('/\D/', '', $message->to_number),
                'type'              => 'text',
                'text'              => ['body' => $message->body],
            ])
            ->throw()
            ->json();

        return DriverSendResult::sent($response);
    }
}
