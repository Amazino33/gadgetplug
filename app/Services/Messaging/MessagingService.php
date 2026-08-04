<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Services\Messaging\Drivers\LogNullDriver;
use App\Services\Messaging\Drivers\MessagingDriver;
use App\Services\Messaging\Drivers\TermiiSmsDriver;
use App\Services\Messaging\Drivers\WaLinkDriver;
use App\Services\Messaging\Drivers\WawpDriver;
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

        $normalized = PhoneNumber::toNigerianInternational($message->to_number);
        if ($normalized !== $message->to_number) {
            $message->to_number = $normalized;
        }

        // The row keeps the real recipient so order history stays truthful about
        // who the message was for; only the outbound copy is diverted.
        $intendedNumber = $message->to_number;
        $intendedBody   = $message->body;
        $redirectedTo   = $this->applyTestRedirect($message);

        try {
            $result = $driver->send($message);
        } catch (Throwable $e) {
            Log::warning('Delivery message send failed.', [
                'delivery_message_id' => $message->id,
                'channel' => $message->channel,
                'exception' => $e->getMessage(),
            ]);

            $result = DriverSendResult::failed(['error' => $e->getMessage()]);
        }

        $message->to_number = $intendedNumber;
        $message->body      = $intendedBody;

        $message->update([
            'to_number' => $intendedNumber,
            'status' => $result->status,
            'provider_response' => $redirectedTo === null
                ? $result->providerResponse
                : ['redirected_to' => $redirectedTo] + (array) $result->providerResponse,
            'sent_at' => in_array($result->status, ['sent', 'link_generated'], true) ? now() : null,
        ]);

        return $message->fresh();
    }

    // Test-mode safety valve: when MESSAGING_REDIRECT_ALL_TO is set, every message
    // goes to that number instead of the real recipient. Lets a provider be
    // exercised end to end through the real app — real orders, real status
    // changes — with no possibility of reaching a customer. Returns the number
    // diverted to, or null when the valve is off.
    private function applyTestRedirect(DeliveryMessage $message): ?string
    {
        $target = PhoneNumber::toNigerianInternational(config('services.messaging.redirect_all_to'));

        if (blank($target) || $target === $message->to_number) {
            return null;
        }

        Log::warning('Delivery message redirected — MESSAGING_REDIRECT_ALL_TO is set. Unset it to reach real customers.', [
            'delivery_message_id' => $message->id,
            'intended_for' => $message->to_number,
            'redirected_to' => $target,
        ]);

        $message->body      = "[TEST — intended for {$message->to_number}]\n\n".$message->body;
        $message->to_number = $target;

        return $target;
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
            // Cloud API first — it's the sanctioned Meta route. Wawp rides a linked
            // phone session, so it's the pragmatic second choice, and wa_link (manual
            // tap) is the last resort when neither is configured.
            'whatsapp' => match (true) {
                filled(config('services.whatsapp_cloud.token')) && filled(config('services.whatsapp_cloud.phone_number_id'))
                    => $this->makeDriver('whatsapp_cloud'),

                filled(config('services.wawp.instance_id')) && filled(config('services.wawp.access_token'))
                    => $this->makeDriver('wawp'),

                default => $this->makeDriver('wa_link'),
            },

            'sms' => filled(config('services.termii.api_key'))
                ? $this->makeDriver('termii')
                : $this->makeDriver('log_null'),

            default => throw new InvalidArgumentException("Unsupported messaging channel [{$channel}]."),
        };
    }

    private function makeDriver(string $key): MessagingDriver
    {
        return match ($key) {
            'termii' => app(TermiiSmsDriver::class),
            'whatsapp_cloud' => app(WhatsAppCloudApiDriver::class),
            'wawp' => app(WawpDriver::class),
            'wa_link' => app(WaLinkDriver::class),
            'log_null' => app(LogNullDriver::class),
            default => throw new InvalidArgumentException("Unknown messaging driver [{$key}]."),
        };
    }
}
