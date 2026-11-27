<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\StockTakeResult;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How often each counter's counts held up when someone else counted the same
 * product again (a recount or a spot-check). A count "held" when the second
 * count found the same (everything on the shelf: good + damaged + expired).
 *
 * The score says whom to trust with the valuable aisles, and who needs a hand.
 */
final class StockTakeAccuracy
{
    /**
     * One row per counter whose counts were checked, worst first.
     *
     * @return Collection<int, array{user_id:int, name:string, checked:int, held:int, percent:int, off_by:int|float}>
     */
    public function forStockTake(int $stockTakeId): Collection
    {
        return $this->rows(fn ($q) => $q->where('original.stock_take_id', $stockTakeId));
    }

    /** The same across every stock take of a branch. */
    public function forStore(int $vendorId, int $storeId): Collection
    {
        return $this->rows(fn ($q) => $q->where('original.vendor_id', $vendorId)->where('original.store_id', $storeId));
    }

    private function rows(callable $scope): Collection
    {
        $query = DB::table('stock_take_results as again')
            ->join('stock_take_results as original', 'original.id', '=', 'again.recount_of_id')
            ->join('stock_take_tasks as task', 'task.id', '=', 'original.stock_take_task_id')
            ->join('users', 'users.id', '=', 'task.assigned_to')
            ->select([
                'users.id as user_id', 'users.name',
                'original.total_counted_base as first_good', 'original.damaged_qty_base as first_damaged', 'original.expired_qty_base as first_expired',
                'again.total_counted_base as second_good', 'again.damaged_qty_base as second_damaged', 'again.expired_qty_base as second_expired',
            ]);

        $scope($query);

        return $query->get()
            ->groupBy('user_id')
            ->map(function (Collection $pairs) {
                $diffs = $pairs->map(fn ($p) => abs((float) Quantity::sub(
                    Quantity::add($p->first_good, $p->first_damaged, $p->first_expired),
                    Quantity::add($p->second_good, $p->second_damaged, $p->second_expired),
                )));
                $held = $diffs->filter(fn ($d) => $d == 0.0)->count();

                return [
                    'user_id' => (int) $pairs->first()->user_id,
                    'name'    => (string) $pairs->first()->name,
                    'checked' => $pairs->count(),
                    'held'    => $held,
                    'percent' => (int) round(100 * $held / max(1, $pairs->count())),
                    'off_by'  => Quantity::of($diffs->sum()),
                ];
            })
            ->sortBy([['percent', 'asc'], ['checked', 'desc']])
            ->values();
    }
}
