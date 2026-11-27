<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Product;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Services\Inventory\StockAtTime;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The results of a handed-in task: one per product, what was counted across
 * the task's aisles against what the shelf held at the moment of counting.
 *
 *   counted     the sum of the product's entries in the task
 *   counted_at  its latest entry: the moment the whole count is compared at
 *   system      stock at counted_at, worked back from the ledger (StockAtTime)
 *               — so a till sale rung before it but synced later is in it
 *   variance    counted − system
 *   value       variance × cost price, the cost basis the audit lines use
 *
 * A result is flagged for recount when it is off by more than the stock
 * take's % or ₦ threshold.
 */
final class StockTakeResults
{
    public function build(StockTakeTask $task): void
    {
        $task->loadMissing('stockTake');
        $stockTake = $task->stockTake;

        if (! $stockTake) {
            return;
        }

        $entries = StockTakeEntry::query()
            ->where('stock_take_task_id', $task->id)
            ->get()
            ->groupBy('product_id');

        $costs = Product::query()->whereIn('id', $entries->keys())->pluck('cost_price', 'id');

        DB::transaction(function () use ($task, $stockTake, $entries, $costs) {
            foreach ($entries as $productId => $productEntries) {
                if (StockTakeResult::query()->where('stock_take_task_id', $task->id)->where('product_id', $productId)->exists()) {
                    continue;
                }

                $countedAt = $productEntries->max('counted_at');
                $counted   = Quantity::add(...$productEntries->pluck('counted_qty_base')->all() ?: [0]);
                $damaged   = Quantity::add(...$productEntries->pluck('damaged_qty_base')->all() ?: [0]);
                $expired   = Quantity::add(...$productEntries->pluck('expired_qty_base')->all() ?: [0]);
                $system    = StockAtTime::at((int) $productId, (int) $task->store_id, $countedAt);
                // Everything on the shelf against the books: damaged and expired
                // are there, so they are not missing — they are written off apart.
                $variance  = Quantity::sub(Quantity::add($counted, $damaged, $expired), $system);
                $unitCost  = ($costs[$productId] ?? null) !== null ? (float) $costs[$productId] : null;
                $value     = $unitCost !== null ? round($variance * $unitCost, 2) : null;

                StockTakeResult::create([
                    'vendor_id'           => $task->vendor_id,
                    'store_id'            => $task->store_id,
                    'stock_take_id'       => $stockTake->id,
                    'stock_take_task_id'  => $task->id,
                    'product_id'          => (int) $productId,
                    'total_counted_base'  => $counted,
                    'damaged_qty_base'    => $damaged,
                    'expired_qty_base'    => $expired,
                    'counted_at'          => $countedAt,
                    'system_qty_at_count' => $system,
                    'variance_qty'        => $variance,
                    'unit_cost'           => $unitCost,
                    'variance_value'      => $value,
                    'needs_recount'       => self::beyondThreshold($system, $variance, $value, (float) $stockTake->variance_threshold_percent, (float) $stockTake->variance_threshold_value),
                    'recount_of_id'       => $task->isRecount() ? $this->originalFor($task, (int) $productId)?->id : null,
                ]);
            }
        });

        self::settle($task);
    }

    /**
     * Off by more than either threshold. A variance against a system figure of
     * 0 is off by "infinitely many percent": always flagged unless it is 0.
     */
    public static function beyondThreshold(int|float $system, int|float $variance, ?float $value, float $percent, float $naira): bool
    {
        if ((float) $variance == 0.0) {
            return false;
        }

        $pct = (float) $system == 0.0 ? INF : abs($variance) / abs($system) * 100;

        return $pct > $percent || ($value !== null && abs($value) > $naira);
    }

    /**
     * A task's status once its results are decided: approved, or partly if any
     * went to recount. Still submitted while any is pending.
     */
    public static function settle(StockTakeTask $task): void
    {
        $task->refresh();

        if (! in_array($task->status, StockTakeScope::HANDED_IN, true)) {
            return;
        }

        $statuses = StockTakeResult::query()->where('stock_take_task_id', $task->id)->pluck('status');

        $next = match (true) {
            $statuses->contains(StockTakeResult::STATUS_PENDING)         => StockTakeTask::STATUS_SUBMITTED,
            $statuses->contains(StockTakeResult::STATUS_SENT_TO_RECOUNT) => StockTakeTask::STATUS_PARTIALLY_APPROVED,
            default                                                      => StockTakeTask::STATUS_APPROVED,
        };

        if ($next !== $task->status) {
            $task->update(['status' => $next]);
        }
    }

    /** On a recount: the original result it re-checks. */
    private function originalFor(StockTakeTask $recount, int $productId): ?StockTakeResult
    {
        return StockTakeResult::query()
            ->where('recount_task_id', $recount->id)
            ->where('product_id', $productId)
            ->latest('id')
            ->first();
    }
}
