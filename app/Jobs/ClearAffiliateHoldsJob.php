<?php

namespace App\Jobs;

use App\Models\AffiliateCommission;
use App\Models\AffiliateSetting;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use App\Services\Affiliate\AffiliateTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Moves every commission whose hold has elapsed from 'return_window' to
// 'available', writing exactly one immutable ledger credit each. Idempotent
// and safe to re-run: it only ever selects rows still in 'return_window', so
// an already-cleared commission is never touched twice, and a mid-run failure
// on one commission doesn't affect the others (each is its own transaction).
class ClearAffiliateHoldsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $cutoff = now()->subDays((int) AffiliateSetting::current()->return_window_days);

        AffiliateCommission::where('status', 'return_window')
            ->where('return_window_started_at', '<=', $cutoff)
            ->chunkById(100, function ($commissions) {
                foreach ($commissions as $commission) {
                    $this->clear($commission);
                }
            });
    }

    private function clear(AffiliateCommission $commission): void
    {
        try {
            DB::transaction(function () use ($commission) {
                // Re-check inside the transaction — another worker could have
                // already cleared this exact row between the query above and
                // this lock being acquired.
                $locked = AffiliateCommission::where('id', $commission->id)
                    ->where('status', 'return_window')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return;
                }

                $locked->update([
                    'status'       => 'available',
                    'available_at' => now(),
                ]);

                $locked->walletTransactions()->create([
                    'affiliate_id' => $locked->affiliate_id,
                    'type'         => 'credit',
                    'amount'       => $locked->amount,
                    'description'  => "Commission #{$locked->id} cleared hold — order #{$locked->order_id}.",
                ]);

                app(AffiliateLevelProgressionService::class)->recompute($locked->affiliate);

                // A cleared sale is the only moment a sales-based auto-task
                // metric can change, so this is the one correct place to
                // re-evaluate them — same reasoning as the progression call above.
                app(AffiliateTaskService::class)->evaluateAuto($locked->affiliate);
            });
        } catch (\Throwable $e) {
            Log::error("Failed to clear affiliate commission #{$commission->id}: " . $e->getMessage());
        }
    }
}
