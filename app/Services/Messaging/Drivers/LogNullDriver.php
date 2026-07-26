<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;

// Dev/testing fallback — records the message as sent without contacting any
// real provider. Used automatically for SMS when no Termii key is configured,
// and always used in the test suite so tests never send real messages.
class LogNullDriver implements MessagingDriver
{
    public function send(DeliveryMessage $message): DriverSendResult
    {
        return DriverSendResult::sent([
            'driver' => 'log_null',
            'note'   => 'No real message was sent — this is the development/testing driver.',
        ]);
    }
}
