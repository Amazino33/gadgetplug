<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\InventoryLedger;
use App\Models\StockTakeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The automatic re-check.
 *
 * A stock movement whose "happened at" is earlier than the moment it reaches
 * the server — a till sale synced late — may fall before the moment of an
 * approved count of that product at that branch. That count's approval did not
 * know about it, so the sale's deduction has just landed a second time.
 *
 * Inside the movement's own transaction: the affected results are only marked
 * (recheck_needed_at). Once that transaction has committed: each is corrected
 * by StockTakeApprover::correctLateSales, under the stock lock, with its own
 * audit line. A failure there never undoes the sale — the mark stays, the
 * dashboard counts it, and the Re-check button posts it by hand.
 */
final class LateSaleRecheck
{
    public static function noticed(InventoryLedger $ledger): void
    {
        if ($ledger->occurred_at === null || $ledger->store_id === null
            || in_array($ledger->transaction_type, InventoryLedger::RESERVATION_ONLY_TYPES, true)) {
            return;
        }

        $arrived = $ledger->created_at ?? now();

        // Recorded as it happened: nothing earlier to catch up with.
        if (! $ledger->occurred_at->lessThan($arrived->copy()->startOfSecond())) {
            return;
        }

        $affected = StockTakeResult::query()
            ->where('store_id', $ledger->store_id)
            ->where('product_id', $ledger->product_id)
            ->where('status', StockTakeResult::STATUS_APPROVED)
            ->where('counted_at', '>', $ledger->occurred_at)
            ->where('approved_at', '<=', $arrived)
            ->pluck('id');

        if ($affected->isEmpty()) {
            return;
        }

        StockTakeResult::query()->whereIn('id', $affected)->update(['recheck_needed_at' => now()]);

        DB::afterCommit(function () use ($affected) {
            foreach ($affected as $id) {
                self::correct((int) $id);
            }
        });
    }

    /** Post one result's correction; on failure leave it marked for the button. */
    public static function correct(int $resultId): void
    {
        $result = StockTakeResult::find($resultId);

        if (! $result || $result->recheck_needed_at === null) {
            return;
        }

        try {
            app(StockTakeApprover::class)->correctLateSales($result, null, StockTakeApprover::AUTO_RECHECK_NOTE);
        } catch (Throwable $e) {
            Log::warning("Stock take auto re-check failed for result #{$resultId}: {$e->getMessage()}");
        }
    }
}
