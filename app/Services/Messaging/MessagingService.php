<?php

namespace App\Services\Messaging;

use App\Models\DeliveryMessage;
use App\Services\Messaging\Drivers\LogNullDriver;
use App\Services\Messaging\Drivers\MessagingDriver;
use App\Services\Messaging\Drivers\TermiiSmsDriver;
use App\Services\Messaging\Drivers\WaLinkDriver;
use App\Services\Messaging\Drivers\WawpDriver;
use App\Services\Messaging\Drivers\WhatsAppCloudApiDriver;
use App\Jobs\SendDeliveryMessageJob;
use Illuminate\Support\Facades\Cache;
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
            // Resolution is inside the try on purpose. It throws on a misconfigured
            // driver or an unknown channel, and this runs from OrderObserver during
            // checkout — so escaping here would turn a messaging config typo into a
            // failed customer order. Degrading to a `failed` row keeps the mistake
            // visible (status, provider_response.error and a log line) without
            // taking the order flow down with it.
            $result = $this->resolveDriver($message->channel)->send($message);
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

    /**
     * Hand an automated message to the queue instead of sending it inline.
     *
     * For anything triggered by a status change, reminder or summary — never
     * for a button the user is watching (those call send() and show the result).
     * The row stays 'queued' until SendDeliveryMessageJob settles it.
     *
     * Never throws: with the sync queue (tests, local) a failing job rethrows
     * into the caller, and a messaging problem must not break an order flow.
     */
    public function dispatch(DeliveryMessage $message): DeliveryMessage
    {
        try {
            SendDeliveryMessageJob::dispatch($message->id);
        } catch (Throwable $e) {
            Log::warning('Could not run queued delivery message.', [
                'delivery_message_id' => $message->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $message->fresh() ?? $message;
    }

    /** The number every message is currently diverted to, or null when the valve is off. */
    public static function redirectTarget(): ?string
    {
        $target = PhoneNumber::toNigerianInternational(config('services.messaging.redirect_all_to'));

        return blank($target) ? null : $target;
    }

    public static function warnRedirectActiveOncePerDay(): void
    {
        if (self::redirectTarget() === null) {
            return;
        }

        if (Cache::add('messaging.redirect-warned.'.now()->toDateString(), true, now()->endOfDay())) {
            Log::warning('MESSAGING_REDIRECT_ALL_TO is set — every WhatsApp/SMS is being diverted to one number and no customer is receiving anything. Unset it to go live.', [
                'redirected_to' => self::redirectTarget(),
            ]);
        }
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

        // Once a day rather than per message: the banner in the admin panel is
        // the standing reminder, and every redirected row records redirected_to
        // in provider_response.
        self::warnRedirectActiveOncePerDay();

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
