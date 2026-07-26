<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Termii SMS send — request/response shape confirmed against
// https://developers.termii.com/messaging-api (2026-07-26).
class TermiiSmsDriver implements MessagingDriver
{
    public function send(DeliveryMessage $message): DriverSendResult
    {
        $apiKey   = config('services.termii.api_key');
        $senderId = config('services.termii.sender_id');

        if (! $apiKey || ! $senderId) {
            throw new RuntimeException('TERMII_API_KEY / TERMII_SENDER_ID are not configured.');
        }

        $response = Http::timeout(15)
            ->post('https://api.ng.termii.com/api/sms/send', [
                'api_key' => $apiKey,
                'to'      => preg_replace('/\D/', '', $message->to_number),
                'from'    => $senderId,
                'sms'     => $message->body,
                'type'    => 'plain',
                'channel' => 'generic',
            ])
            ->throw()
            ->json();

        return DriverSendResult::sent($response);
    }
}
