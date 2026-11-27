<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryLedger;
use App\Models\ProductStoreStock;
use App\Support\Quantity;
use Carbon\CarbonInterface;

/**
 * What a branch held of one product at a moment in the past.
 *
 * Worked backwards from the live row: stock now, minus every movement that
 * happened after the moment. That is the figure a count taken at that moment
 * should be compared with — not stock now, which has moved on with every sale
 * since, and not a figure read when the count was submitted or approved, which
 * has moved on with every sale in between.
 *
 * "Happened" is the ledger's occurred_at, not created_at: a till sale rung
 * before the count but synced hours after it belongs before, and is already
 * gone from the shelf the counter looked at.
 *
 * Exact only while the caller holds the product and store row locks (as
 * AdjustStockAction takes them). Unlocked, a sale can land between reading the
 * row and reading the ledger, which is fine for display and wrong for posting.
 *
 * Movements that change the row without a ledger entry (a product's opening
 * stock when it is created) are invisible here; they predate any count of
 * that product, so they never fall after a counted moment.
 */
final class StockAtTime
{
    public static function at(int $productId, int $storeId, CarbonInterface $moment): int|float
    {
        $now = ProductStoreStock::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('quantity') ?? 0;

        return Quantity::sub($now, self::movedBetween($productId, $storeId, $moment, null));
    }

    /**
     * Net units moved on the shelf in (from, to] — to = null meaning "up to
     * now". Reservations are left out: they earmark stock without moving it.
     */
    public static function movedBetween(int $productId, int $storeId, CarbonInterface $from, ?CarbonInterface $to): int|float
    {
        return Quantity::of(InventoryLedger::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->whereNotIn('transaction_type', InventoryLedger::RESERVATION_ONLY_TYPES)
            ->where('occurred_at', '>', $from)
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->sum('quantity_change'));
    }
}
