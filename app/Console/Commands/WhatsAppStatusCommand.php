<?php

namespace App\Console\Commands;

use App\Services\Messaging\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

// Answers "what will customers actually see, and is anything about to reach them
// that shouldn't?" without sending a message. Read-only: it queries the session
// endpoint rather than the send endpoint, so it is safe to run at any time.
class WhatsAppStatusCommand extends Command
{
    protected $signature = 'whatsapp:status';

    protected $description = 'Show the configured WhatsApp provider, linked sender number, and test-redirect state';

    public function handle(): int
    {
        $driver     = config('services.messaging.whatsapp_driver') ?: '(auto-select)';
        $redirect   = config('services.messaging.redirect_all_to');
        $instanceId = config('services.wawp.instance_id');
        $token      = config('services.wawp.access_token');

        $this->newLine();
        $this->line('WhatsApp driver:  <info>'.$driver.'</info>');
        $this->line('Wawp instance:    <info>'.($instanceId ?: '(not configured)').'</info>');

        // The most consequential line in this output: while a redirect is set,
        // nothing reaches a real customer, and forgetting it is switched on looks
        // exactly like a working system.
        if (filled($redirect)) {
            $this->newLine();
            $this->warn('TEST REDIRECT IS ON — every message goes to '.PhoneNumber::toNigerianInternational($redirect).'.');
            $this->warn('No customer or storekeeper receives anything until MESSAGING_REDIRECT_ALL_TO is removed.');
        } else {
            $this->newLine();
            $this->line('Test redirect:    <info>off — messages go to real recipients</info>');
        }

        if (blank($instanceId) || blank($token)) {
            $this->newLine();
            $this->error('WAWP_INSTANCE_ID / WAWP_ACCESS_TOKEN are not configured, so no session to check.');

            return self::FAILURE;
        }

        return $this->reportSession($instanceId, $token);
    }

    private function reportSession(string $instanceId, string $token): int
    {
        try {
            $response = Http::timeout(15)
                ->asJson()
                ->post('https://api.wawp.net/v2/session/info', [
                    'instance_id'  => $instanceId,
                    'access_token' => $token,
                ]);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Could not reach Wawp: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->newLine();
            $this->error('Wawp returned HTTP '.$response->status().': '.($response->json('code') ?? 'unknown'));
            $this->line((string) $response->json('message'));
            $this->newLine();
            $this->line('A 404 invalid_session means the linked phone dropped — re-scan the QR in the Wawp dashboard.');

            return self::FAILURE;
        }

        $status = $response->json('status');
        $sender = $response->json('me.id');
        $name   = $response->json('me.pushName');

        $this->newLine();
        $this->line('Session status:   '.($status === 'WORKING' ? '<info>'.$status.'</info>' : '<error>'.$status.'</error>'));
        $this->line('Sender number:    <info>'.($sender ?: 'unknown').'</info>');
        $this->line('Sender name:      <info>'.($name ?: 'unknown').'</info>');
        $this->newLine();
        $this->line('Customers see the sender name and number above. If that is not your');
        $this->line('business line, re-scan the QR for this instance in the Wawp dashboard.');
        $this->newLine();

        return $status === 'WORKING' ? self::SUCCESS : self::FAILURE;
    }
}
