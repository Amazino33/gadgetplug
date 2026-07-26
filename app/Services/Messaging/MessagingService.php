<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Services\Messaging\Drivers\LogNullDriver;
use App\Services\Messaging\Drivers\MessagingDriver;
use App\Services\Messaging\Drivers\TermiiSmsDriver;
use App\Services\Messaging\Drivers\WaLinkDriver;
use App\Services\Messaging\Drivers\WhatsAppCloudApiDriver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

// Sends a DeliveryMessage through whichever driver is configured/available
// for its channel, then updates the same row with the outcome. The UI never
// needs to know which driver actually ran — every driver returns the same
// DriverSendResult shape, and a driver failure here degrades to a `failed`
// status instead of throwing, so a provider outage never breaks the order page.
class MessagingService
{
    public function send(DeliveryMessage $message): DeliveryMessage
    {
        $driver = $this->resolveDriver($message->channel);

        try {
            $result = $driver->send($message);
        } catch (Throwable $e) {
            Log::warning('Delivery message send failed.', [
                'delivery_message_id' => $message->id,
                'channel'             => $message->channel,
                'exception'           => $e->getMessage(),
            ]);

            $result = DriverSendResult::failed(['error' => $e->getMessage()]);
        }

        $message->update([
            'status'            => $result->status,
            'provider_response' => $result->providerResponse,
            'sent_at'           => in_array($result->status, ['sent', 'link_generated'], true) ? now() : null,
        ]);

        return $message->fresh();
    }

    private function resolveDriver(string $channel): MessagingDriver
    {
        $configured = config("services.messaging.{$channel}_driver");

        if ($configured) {
            return $this->makeDriver($configured);
        }

        // Automation is the default: use the real provider when it's configured,
        // otherwise fall back to the free/manual option for that channel.
        return match ($channel) {
            'whatsapp' => (filled(config('services.whatsapp_cloud.token')) && filled(config('services.whatsapp_cloud.phone_number_id')))
                ? $this->makeDriver('whatsapp_cloud')
                : $this->makeDriver('wa_link'),

            'sms' => filled(config('services.termii.api_key'))
                ? $this->makeDriver('termii')
                : $this->makeDriver('log_null'),

            default => throw new InvalidArgumentException("Unsupported messaging channel [{$channel}]."),
        };
    }

    private function makeDriver(string $key): MessagingDriver
    {
        return match ($key) {
            'termii'         => app(TermiiSmsDriver::class),
            'whatsapp_cloud' => app(WhatsAppCloudApiDriver::class),
            'wa_link'        => app(WaLinkDriver::class),
            'log_null'       => app(LogNullDriver::class),
            default          => throw new InvalidArgumentException("Unknown messaging driver [{$key}]."),
        };
    }
}
