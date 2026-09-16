<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CashSubmission;
use App\Models\Store;
use App\Models\VendorNotificationSetting;
use App\Services\Cash\CashDrawer;
use App\Services\Cash\StoreReconciliation;
use App\Services\Messaging\StorekeeperNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tell a branch it is sitting on takings before the settlement does.
 *
 * The threshold is StoreReconciliation::GRACE_DAYS rather than a number of its
 * own, on purpose: this fires at exactly the moment unremitted cash would start
 * counting as a shortage against somebody, so the warning and the accusation
 * can never drift apart and say different things about the same money.
 */
class AlertUnremittedCashCommand extends Command
{
    protected $signature = 'cash:alert-unremitted {--dry-run : List who would be told, send nothing}';

    protected $description = 'Warn branches holding cash they have not handed over';

    public function handle(StorekeeperNotifier $notifier): int
    {
        $cutoff = now()->subDays(StoreReconciliation::GRACE_DAYS);
        $sent = 0;

        foreach (Store::with('vendor')->where('is_active', true)->cursor() as $store) {
            if (! $store->vendor) {
                continue;
            }

            // Everything taken up to the point it stopped being fresh, less
            // everything ever handed over. What is left has been sat on.
            $held = round(CashDrawer::takingsIn(
                $store->vendor_id,
                $store->id,
                from: Carbon::create(2000, 1, 1),
                to: $cutoff,
            ) - $this->submittedTotal($store->id), 2);

            if ($held < 0.01) {
                continue;
            }

            $settings = VendorNotificationSetting::forVendor($store->vendor);

            // Sent once per grace window, not once per scheduler tick. A balance
            // that sits for a fortnight should produce a handful of reminders,
            // not three hundred.
            if ($settings->unremitted_cash_alerted_at
                && $settings->unremitted_cash_alerted_at->gt(now()->subDays(StoreReconciliation::GRACE_DAYS))) {
                continue;
            }

            $days = (int) $this->oldestUnremittedDays($store->id);

            $this->line(sprintf('%s: %s held, oldest %d days', $store->name, number_format($held, 2), $days));

            if ($this->option('dry-run')) {
                continue;
            }

            if ($notifier->unremittedCashAlert($store->vendor, $store, $held, $days)) {
                $settings->forceFill(['unremitted_cash_alerted_at' => now()])->save();
                $sent++;
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run — nothing sent.' : "Sent {$sent} alert(s).");

        return self::SUCCESS;
    }

    private function submittedTotal(int $storeId): float
    {
        return round((float) CashSubmission::query()
            ->where('store_id', $storeId)
            ->againstBalance()
            ->sum('amount'), 2);
    }

    /** How long the branch has gone without handing anything over. */
    private function oldestUnremittedDays(int $storeId): int
    {
        $last = CashSubmission::query()
            ->where('store_id', $storeId)
            ->againstBalance()
            ->max('created_at');

        return $last
            ? (int) Carbon::parse($last)->diffInDays(now())
            : StoreReconciliation::GRACE_DAYS;
    }
}
