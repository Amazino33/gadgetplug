<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\DailySummaryNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

// Runs hourly and lets each vendor's own daily_summary_time decide whether the
// summary is due, so the send hour is data rather than a schedule definition and
// a vendor changing it takes effect on the next tick with no deploy.
class SendVendorDailySummaryCommand extends Command
{
    protected $signature = 'vendor:daily-summary
                            {--vendor= : Restrict to one vendor id}
                            {--date= : Business date to summarise (Y-m-d), defaults to yesterday}
                            {--force : Ignore the configured hour, the already-sent guard, and the quiet-day skip}';

    protected $description = 'Send each vendor the WhatsApp summary of a day of trading';

    public function handle(DailySummaryNotifier $notifier): int
    {
        $now   = Carbon::now();
        $force = (bool) $this->option('force');

        $vendors = Vendor::query()
            ->when($this->option('vendor'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($vendors as $vendor) {
            $settings = VendorNotificationSetting::forVendor($vendor);

            $date = $this->resolveDate($settings, $now, $force);

            if ($date === null) {
                $skipped++;

                continue;
            }

            try {
                $message = $notifier->send($vendor, $date, $force);
            } catch (Throwable $e) {
                Log::warning('Vendor daily summary failed.', [
                    'vendor_id' => $vendor->id,
                    'exception' => $e->getMessage(),
                ]);
                $this->error("Vendor {$vendor->id}: {$e->getMessage()}");

                continue;
            }

            if ($message === null) {
                // Switched off, no owner number, no template, or a day with
                // nothing on it. Deliberately does not stamp the watermark on a
                // quiet day, so a late-posted expense still gets reported if the
                // next tick finds something.
                $skipped++;

                continue;
            }

            $settings->update(['last_daily_summary_for' => $date->toDateString()]);
            $sent++;

            $this->line("Vendor {$vendor->id} ({$vendor->name}): {$date->toDateString()} — {$message->status}.");
        }

        $this->info("Done. {$sent} summary(ies) sent, {$skipped} vendor(s) skipped.");

        return self::SUCCESS;
    }

    private function resolveDate(VendorNotificationSetting $settings, Carbon $now, bool $force): ?Carbon
    {
        if ($explicit = $this->option('date')) {
            return Carbon::parse($explicit)->startOfDay();
        }

        if ($force) {
            return $now->copy()->subDay()->startOfDay();
        }

        $due = $settings->dailySummaryDueFor($now);

        return $due ? Carbon::instance($due->toDateTime()) : null;
    }
}
