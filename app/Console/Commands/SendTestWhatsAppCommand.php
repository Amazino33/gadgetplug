<?php

namespace App\Console\Commands;

use App\Models\DeliveryMessage;
use App\Services\Messaging\Drivers\MessagingDriver;
use App\Services\Messaging\Drivers\WaLinkDriver;
use App\Services\Messaging\Drivers\WawpDriver;
use App\Services\Messaging\Drivers\WhatsAppCloudApiDriver;
use App\Services\Messaging\DriverSendResult;
use App\Services\Messaging\PhoneNumber;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

// Smoke-tests a WhatsApp provider end to end without needing a real order.
// Deliberately does NOT touch the delivery_messages table — a throwaway
// unsaved model stands in, so a credential check never leaves a stray row
// attached to some vendor's order history.
class SendTestWhatsAppCommand extends Command
{
    protected $signature = 'whatsapp:test
                            {phone : Recipient number, local or international (e.g. 08136310313)}
                            {--message=Test message from GadgetPlug. : Body to send}
                            {--driver= : Force a driver (wawp, whatsapp_cloud, wa_link); defaults to the configured one}';

    protected $description = 'Send a single test WhatsApp message to verify provider credentials';

    public function handle(): int
    {
        $normalized = PhoneNumber::toNigerianInternational($this->argument('phone'));
        $body       = (string) $this->option('message');
        $driverKey  = $this->option('driver') ?: config('services.messaging.whatsapp_driver') ?: 'wawp';

        $this->line("Driver:    <info>{$driverKey}</info>");
        $this->line("Recipient: <info>{$normalized}</info>");
        $this->line("Body:      <info>{$body}</info>");
        $this->newLine();

        if (! $this->confirm('This sends a real WhatsApp message. Continue?', true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        try {
            $result = $this->driverFor($driverKey)->send($this->stubMessage($normalized, $body));
        } catch (Throwable $e) {
            $this->error('Send threw: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->report($result);
    }

    private function report(DriverSendResult $result): int
    {
        $this->newLine();
        $this->line('Status: '.match ($result->status) {
            'sent'           => '<info>sent</info>',
            'link_generated' => '<comment>link_generated (needs a manual tap)</comment>',
            default          => '<error>'.$result->status.'</error>',
        });

        $this->line('Provider response:');
        $this->line(json_encode($result->providerResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function driverFor(string $key): MessagingDriver
    {
        return match ($key) {
            'wawp'           => app(WawpDriver::class),
            'whatsapp_cloud' => app(WhatsAppCloudApiDriver::class),
            'wa_link'        => app(WaLinkDriver::class),
            default          => throw new InvalidArgumentException("Unknown WhatsApp driver [{$key}]."),
        };
    }

    // Drivers only read to_number and body, so an unsaved DeliveryMessage
    // satisfies the type hint while keeping the test send out of the database.
    private function stubMessage(string $toNumber, string $body): DeliveryMessage
    {
        return new DeliveryMessage([
            'to_number' => $toNumber,
            'body'      => $body,
        ]);
    }
}
