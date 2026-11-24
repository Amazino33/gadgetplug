<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How often each product was rung up at a branch lately — what product search
 * uses to put the everyday item above its rarely-sold cousins.
 *
 * Counted as sales a product appeared in, not units: one wholesale sale of a
 * hundred crates should not outrank the bottle sold forty times a day.
 * Voided sales do not count. Cached briefly: it only orders search results,
 * and is read for every page of a till's catalogue.
 */
class SalesPopularity
{
    public const DAYS = 30;

    private const CACHE_SECONDS = 900;

    /** @return array<int, int> product id → sales it appeared in */
    public function forStore(int $vendorId, ?int $storeId): array
    {
        return Cache::remember(
            "search-popularity:{$vendorId}:".($storeId ?? 'all'),
            self::CACHE_SECONDS,
            fn () => DB::table('pos_sale_items')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
                ->where('pos_sales.vendor_id', $vendorId)
                ->when($storeId, fn ($q) => $q->where('pos_sales.store_id', $storeId))
                ->where('pos_sales.status', '!=', 'voided')
                ->where('pos_sales.completed_at', '>=', now()->subDays(self::DAYS))
                ->groupBy('pos_sale_items.product_id')
                ->selectRaw('pos_sale_items.product_id, COUNT(DISTINCT pos_sale_items.pos_sale_id) AS sales')
                ->pluck('sales', 'product_id')
                ->map(fn ($n) => (int) $n)
                ->all(),
        );
    }
}
