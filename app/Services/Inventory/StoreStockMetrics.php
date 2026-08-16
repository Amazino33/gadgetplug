<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// What a store is holding, read straight from product_store_stock.
//
// Deliberately not from products.stock_quantity: that column is the vendor-wide
// mirror, so using it would print the same totals on every store card. These
// numbers are the whole reason the grid is worth looking at.
//
// Today's sales is absent on purpose — order_items carries no store until a
// later phase, so any per-store sales figure here would be invented.
class StoreStockMetrics
{
    /**
     * One row per store id, so a grid of cards costs a single query.
     *
     * @param  Collection<int, int>|array<int, int>  $storeIds
     * @return Collection<int, object{product_count: int, units: int, cost_value: float, retail_value: float, low_stock_count: int}>
     */
    public static function forStores(Collection|array $storeIds): Collection
    {
        $storeIds = collect($storeIds)->all();

        if ($storeIds === []) {
            return collect();
        }

        return DB::table('product_store_stock as pss')
            ->join('products as p', 'p.id', '=', 'pss.product_id')
            ->whereIn('pss.store_id', $storeIds)
            ->groupBy('pss.store_id')
            ->select('pss.store_id')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(pss.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(pss.quantity * COALESCE(p.cost_price, 0)), 0) as cost_value')
            ->selectRaw('COALESCE(SUM(pss.quantity * COALESCE(p.price, 0)), 0) as retail_value')
            // Same boundary Product::getIsLowStockAttribute() uses — available
            // above zero but under the product's own threshold — measured on
            // this store's row rather than the vendor total.
            ->selectRaw('SUM(CASE WHEN (pss.quantity - pss.reserved) > 0 AND (pss.quantity - pss.reserved) < COALESCE(p.low_stock_threshold, 0) THEN 1 ELSE 0 END) as low_stock_count')
            ->get()
            ->keyBy('store_id')
            ->map(fn ($row) => (object) [
                'product_count'   => (int) $row->product_count,
                'units'           => (int) $row->units,
                'cost_value'      => (float) $row->cost_value,
                'retail_value'    => (float) $row->retail_value,
                'low_stock_count' => (int) $row->low_stock_count,
            ]);
    }

    public static function empty(): object
    {
        return (object) [
            'product_count'   => 0,
            'units'           => 0,
            'cost_value'      => 0.0,
            'retail_value'    => 0.0,
            'low_stock_count' => 0,
        ];
    }
}
