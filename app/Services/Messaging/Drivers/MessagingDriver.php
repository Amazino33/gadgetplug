<?php

namespace App\Services\Messaging\Drivers;

use App\Models\DeliveryMessage;
use App\Services\Messaging\DriverSendResult;

interface MessagingDriver
{
    public function send(DeliveryMessage $message): DriverSendResult;
}
