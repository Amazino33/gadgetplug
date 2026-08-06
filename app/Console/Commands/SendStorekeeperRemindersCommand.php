<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\StorekeeperNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

// Runs hourly from the scheduler and decides per vendor whether a reminder is
// due. Cadence lives in the database rather than in the schedule definition, so
// a store changing its frequency takes effect on the next hourly tick with no
// deploy and no rescheduling.
class SendStorekeeperRemindersCommand extends Command
{
    protected $signature = 'storekeeper:remind
                            {--vendor= : Restrict to one vendor id}
                            {--force : Ignore cadence and quiet hours (for testing)}';

    protected $description = 'Send due storekeeper reminders about orders awaiting dispatch';

    public function handle(StorekeeperNotifier $notifier): int
    {
        $now = Carbon::now();

        $vendors = Vendor::query()
            ->when($this->option('vendor'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($vendors as $vendor) {
            $settings = VendorNotificationSetting::forVendor($vendor);

            if (! $this->option('force') && ! $settings->reminderDueAt($now)) {
                $skipped++;

                continue;
            }

            if ($this->option('force') && ! $settings->hasStorekeeperNumber()) {
                $this->warn("Vendor {$vendor->id} ({$vendor->name}) has no storekeeper WhatsApp number set.");
                $skipped++;

                continue;
            }

            try {
                $messages = array_filter([
                    'awaiting dispatch' => $notifier->undispatchedReminder($vendor, $now),
                    'low stock'         => $notifier->lowStockAlert($vendor),
                ]);
            } catch (Throwable $e) {
                Log::warning('Storekeeper reminder failed.', [
                    'vendor_id' => $vendor->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Vendor {$vendor->id}: {$e->getMessage()}");

                continue;
            }

            if ($messages === []) {
                // Nothing outstanding and nothing low. Deliberately does not stamp
                // last_reminder_sent_at, so the next tick after something does go
                // wrong alerts immediately rather than waiting out a full cycle.
                $skipped++;

                continue;
            }

            $settings->update(['last_reminder_sent_at' => $now]);
            $sent += count($messages);

            foreach ($messages as $kind => $message) {
                $this->line("Vendor {$vendor->id} ({$vendor->name}): {$kind} — {$message->status}.");
            }
        }

        $this->info("Done. {$sent} reminder(s) sent, {$skipped} vendor(s) skipped.");

        return self::SUCCESS;
    }
}
