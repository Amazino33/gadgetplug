<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\PhysicalStockCount;
use App\Models\PhysicalStockCountLine;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Enter what was found on the shelf for a branch over a period.
 *
 * The system's own figure is read and frozen here, inside the same transaction
 * as the count, so the two figures being compared were true at the same instant.
 * Reading it later would compare a count taken on Friday against stock that has
 * moved all weekend, and produce a variance that means nothing.
 *
 * This deliberately does not adjust stock. A count is evidence, and what to do
 * about a gap — write it off, charge it to somebody, recount — is a decision for
 * the settlement conversation, not an automatic correction that would erase the
 * discrepancy it just found.
 */
class RecordPhysicalCountAction
{
    /**
     * @param  array<int, int>  $counts  product id => units found
     */
    public function execute(
        User $countedBy,
        Store $store,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        array $counts,
        ?string $note = null,
    ): PhysicalStockCount {
        if ($counts === []) {
            throw new RuntimeException('A count has to cover at least one product.');
        }

        return DB::transaction(function () use ($countedBy, $store, $periodStart, $periodEnd, $counts, $note) {
            $count = PhysicalStockCount::create([
                'vendor_id'    => $store->vendor_id,
                'store_id'     => $store->id,
                'counted_by'   => $countedBy->id,
                'period_start' => $periodStart,
                'period_end'   => $periodEnd,
                'counted_at'   => now(),
                'note'         => $note,
                // Set here rather than left to the column default, so the model
                // handed back to the caller says what it is without a refresh.
                'status'       => PhysicalStockCount::STATUS_SUBMITTED,
            ]);

            $systemQuantities = ProductStoreStock::query()
                ->where('store_id', $store->id)
                ->whereIn('product_id', array_keys($counts))
                ->pluck('quantity', 'product_id');

            $products = Product::query()
                ->whereIn('id', array_keys($counts))
                ->get(['id', 'cost_price', 'price'])
                ->keyBy('id');

            foreach ($counts as $productId => $found) {
                if ($found < 0) {
                    throw new RuntimeException('A count cannot be negative.');
                }

                PhysicalStockCountLine::create([
                    'physical_stock_count_id' => $count->id,
                    'product_id'              => $productId,
                    'counted_quantity'        => (int) $found,
                    // Absent means the branch holds no row for it, which is a
                    // system figure of zero — and counting something the system
                    // does not think is there is exactly the kind of gap worth
                    // seeing.
                    'system_quantity'         => (int) ($systemQuantities[$productId] ?? 0),
                    // Both snapshotted now, for the same reason the system
                    // quantity is: a price that moved next week would silently
                    // restate what this gap was worth.
                    'unit_cost'               => $products[$productId]?->cost_price,
                    'unit_price'              => $products[$productId]?->price,
                ]);
            }

            return $count->load('lines');
        });
    }
}
