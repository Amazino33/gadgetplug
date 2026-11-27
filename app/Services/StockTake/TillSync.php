<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\PosTillSyncState;
use Illuminate\Support\Collection;

/**
 * Tills that last reported unsynced sales at a branch. Until they sync, a
 * count approved there may later be shifted by a sale rung before it — the
 * approval screen warns, and Re-check corrects afterwards.
 */
final class TillSync
{
    /** @return Collection<int, PosTillSyncState> */
    public static function pendingAt(int $storeId): Collection
    {
        return PosTillSyncState::query()
            ->where('store_id', $storeId)
            ->where('pending_sales', '>', 0)
            ->with('user:id,name')
            ->orderBy('oldest_pending_at')
            ->get();
    }
}
