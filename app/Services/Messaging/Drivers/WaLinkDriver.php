<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;

// Free fallback for WhatsApp when no Cloud API credentials are configured —
// generates a wa.me deep link prefilled with the message body. Needs one
// human tap to actually send, unlike the fully-automated drivers.
class WaLinkDriver implements MessagingDriver
{
    public function send(DeliveryMessage $message): DriverSendResult
    {
        $phone = preg_replace('/\D/', '', $message->to_number);
        $url   = 'https://api.whatsapp.com/send?phone=' . $phone . '&text=' . rawurlencode($message->body);

        return DriverSendResult::linkGenerated([
            'driver' => 'wa_link',
            'url'    => $url,
        ]);
    }
}
