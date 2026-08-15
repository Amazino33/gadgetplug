<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\InventoryLedger;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Reusable velocity + stock-availability classifier — the restock report
// reads this directly, and the dead-stock / product-matrix reports (built
// later) read the same result set rather than re-deriving "what sold" on
// their own.
//
// "Units delivered" combines both sales channels this app has: online orders
// (via FinancialReportService::recognizedOrderItemsQuery() — the exact join
// the P&L uses for revenue, so velocity and revenue can never disagree about
// an online order) and completed POS sales (PosSale has no relationship to
// Order and never touches revenue_recognized_at, so it needs its own query;
// "completed" here means any non-voided status — completed/refunded/
// partial_refund all mean the goods left the shelf, matching how online
// orders also don't reduce revenue for a post-delivery return today).
//
// Nothing here is cached — every call re-derives from live data, same
// discipline as FinancialReportService.
class ProductVelocityService
{
    public function forVendor(
        int $vendorId,
        ?int $categoryId = null,
        int $windowDays = 30,
        int $leadTimeDays = 5,
        int $targetCoverDays = 30,
        ?int $safetyBufferDays = null,
        bool $withStockoutGuard = true,
    ): Collection {
        $products = Product::where('vendor_id', $vendorId)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->get(['id', 'vendor_id', 'stock_quantity', 'created_at']);

        return $this->analyze($products, $windowDays, $leadTimeDays, $targetCoverDays, $safetyBufferDays, $withStockoutGuard);
    }

    // Ranks products by units actually sold in an arbitrary [from, to] range
    // — "who sold the most this week/month" — as opposed to forVendor()'s
    // trailing-window restock tiering. Reuses the exact same two sources
    // (recognizedOrderItemsQuery for online, the same POS filter for POS) so
    // "top sellers" and "restock velocity" can never disagree about a sale.
    /**
     * @return Collection<int, TopSellerRow>
     */
    public function topSellers(
        int $vendorId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $categoryId = null,
        int $limit = 20,
    ): Collection {
        $onlineRows = FinancialReportService::recognizedOrderItemsQuery($vendorId, $from, $to)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.unit_price) as revenue')
            ->get()
            ->keyBy('product_id');

        $posRows = FinancialReportService::recognizedPosSaleItemsQuery($vendorId, $from, $to)
            ->groupBy('pos_sale_items.product_id')
            ->selectRaw('pos_sale_items.product_id, SUM(pos_sale_items.quantity) as units, SUM(pos_sale_items.quantity * pos_sale_items.unit_price) as revenue')
            ->get()
            ->keyBy('product_id');

        $productIds = $onlineRows->keys()->merge($posRows->keys())->unique();

        if ($productIds->isEmpty()) {
            return collect();
        }

        $products = Product::whereIn('id', $productIds)
            ->where('vendor_id', $vendorId)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        // Normalized to calendar-day boundaries before diffing — diffing the
        // raw timestamps is one day short or long depending on the time-of-day
        // each end happens to carry (e.g. a 4-day-and-23-hours gap rounds
        // unpredictably), where diffing two midnights never does.
        $days = max(1, CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay()) + 1);

        return $productIds
            ->filter(fn ($id) => $products->has($id))
            ->map(function ($id) use ($onlineRows, $posRows, $products, $days) {
                $units = (int) (($onlineRows[$id]->units ?? 0) + ($posRows[$id]->units ?? 0));
                $revenue = (float) (($onlineRows[$id]->revenue ?? 0) + ($posRows[$id]->revenue ?? 0));

                return new TopSellerRow(
                    product: $products[$id],
                    unitsSold: $units,
                    revenue: $revenue,
                    dailyVelocity: $units / $days,
                );
            })
            ->sortByDesc(fn (TopSellerRow $row) => $row->unitsSold)
            ->values()
            ->take($limit);
    }

    public function forProduct(
        Product $product,
        int $windowDays = 30,
        int $leadTimeDays = 5,
        int $targetCoverDays = 30,
        ?int $safetyBufferDays = null,
        bool $withStockoutGuard = true,
    ): RestockAnalysisResult {
        return $this->analyze(collect([$product]), $windowDays, $leadTimeDays, $targetCoverDays, $safetyBufferDays, $withStockoutGuard)
            ->get($product->id);
    }

    /**
     * @param  Collection<int, Product>  $products  All from the same vendor.
     * @return Collection<int, RestockAnalysisResult> Keyed by product_id.
     */
    private function analyze(
        Collection $products,
        int $windowDays,
        int $leadTimeDays,
        int $targetCoverDays,
        ?int $safetyBufferDays,
        bool $withStockoutGuard,
    ): Collection {
        if ($products->isEmpty()) {
            return collect();
        }

        $safetyBufferDays ??= $leadTimeDays;
        $vendorId = $products->first()->vendor_id;
        $now = CarbonImmutable::now();
        $windowStart = $now->subDays($windowDays);
        $productIds = $products->pluck('id')->all();

        $onlineUnits = $this->onlineUnitsDelivered($vendorId, $productIds, $windowStart, $now);
        $posUnits = $this->posUnitsDelivered($vendorId, $productIds, $windowStart, $now);

        return $products->mapWithKeys(function (Product $product) use (
            $onlineUnits, $posUnits, $windowDays, $leadTimeDays, $targetCoverDays,
            $safetyBufferDays, $withStockoutGuard, $vendorId, $windowStart, $now,
        ) {
            $unitsDelivered = ($onlineUnits[$product->id] ?? 0) + ($posUnits[$product->id] ?? 0);
            // PHP's / returns an int when both operands are ints and evenly
            // divisible (0 / 30 === 0, not 0.0) — force float so the strict
            // === 0.0 check below (and the DTO's float type) are reliable.
            $dailyVelocity = (float) $unitsDelivered / $windowDays;
            $currentStock = (int) $product->stock_quantity;
            $isNew = $product->created_at->greaterThan($windowStart);

            $daysOfCover = $dailyVelocity > 0 ? $currentStock / $dailyVelocity : null;

            $daysOutOfStock = null;
            if ($withStockoutGuard && $dailyVelocity === 0.0) {
                $daysOutOfStock = $this->daysOutOfStockForProduct($vendorId, $product->id, $windowStart, $now, $windowDays);
            }

            $tier = $this->classify($isNew, $dailyVelocity, $currentStock, $daysOfCover, $leadTimeDays, $safetyBufferDays, $daysOutOfStock, $windowDays);

            // "No quantity from thin data" — Watch never gets a suggested
            // number, no matter what the raw formula would say.
            $reorderQuantity = 0;
            if (! $isNew && in_array($tier, [RestockAnalysisResult::TIER_URGENT, RestockAnalysisResult::TIER_REORDER_NOW], true)) {
                $reorderQuantity = (int) ceil(max(0, $targetCoverDays * $dailyVelocity - $currentStock));
            }

            return [$product->id => new RestockAnalysisResult(
                productId: $product->id,
                dailyVelocity: $dailyVelocity,
                currentStock: $currentStock,
                daysOfCover: $daysOfCover,
                reorderQuantity: $reorderQuantity,
                tier: $tier,
                isNew: $isNew,
                daysOutOfStock: $daysOutOfStock,
            )];
        });
    }

    private function classify(
        bool $isNew,
        float $dailyVelocity,
        int $currentStock,
        ?float $daysOfCover,
        int $leadTimeDays,
        int $safetyBufferDays,
        ?int $daysOutOfStock,
        int $windowDays,
    ): string {
        if ($isNew) {
            return RestockAnalysisResult::TIER_WATCH;
        }

        if ($dailyVelocity > 0) {
            if ($daysOfCover <= $leadTimeDays) {
                return RestockAnalysisResult::TIER_URGENT;
            }

            if ($daysOfCover <= $leadTimeDays + $safetyBufferDays) {
                return RestockAnalysisResult::TIER_REORDER_NOW;
            }

            return RestockAnalysisResult::TIER_HEALTHY;
        }

        // Zero velocity: out of stock for most of the window explains the
        // zero without meaning "nobody wants this" — a starved bestseller
        // must never read the same as genuine dead stock.
        $isStarved = $daysOutOfStock !== null && $daysOutOfStock >= ($windowDays / 2);

        if ($isStarved) {
            return RestockAnalysisResult::TIER_STARVED_REVIEW;
        }

        return $currentStock > 0
            ? RestockAnalysisResult::TIER_DEAD_STOCK_CANDIDATE
            : RestockAnalysisResult::TIER_REVIEW;
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, int> units by product_id
     */
    private function onlineUnitsDelivered(int $vendorId, array $productIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return FinancialReportService::recognizedOrderItemsQuery($vendorId, $from, $to)
            ->whereIn('order_items.product_id', $productIds)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units')
            ->pluck('units', 'product_id')
            ->map(fn ($units) => (int) $units)
            ->all();
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, int> units by product_id
     */
    private function posUnitsDelivered(int $vendorId, array $productIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return FinancialReportService::recognizedPosSaleItemsQuery($vendorId, $from, $to)
            ->whereIn('pos_sale_items.product_id', $productIds)
            ->groupBy('pos_sale_items.product_id')
            ->selectRaw('pos_sale_items.product_id, SUM(pos_sale_items.quantity) as units')
            ->pluck('units', 'product_id')
            ->map(fn ($units) => (int) $units)
            ->all();
    }

    // Reconstructs a running stock balance from the ledger and totals whole
    // days spent at or below zero within the window — the only way to answer
    // "was this out of stock" since no convenience column tracks it directly.
    // Only ever called for a zero-velocity product, so the event count per
    // call is small regardless of catalogue size.
    private function daysOutOfStockForProduct(int $vendorId, int $productId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, int $windowDays): int
    {
        // No ledger history at all is "unknown," not "out of stock the whole
        // window" — a product that was never stocked isn't a starved
        // bestseller, it's genuinely ambiguous (Review, not Starved).
        $hasAnyHistory = InventoryLedger::where('vendor_id', $vendorId)
            ->where('product_id', $productId)
            ->exists();

        if (! $hasAnyHistory) {
            return 0;
        }

        $startingBalance = (int) InventoryLedger::where('vendor_id', $vendorId)
            ->where('product_id', $productId)
            ->where('created_at', '<', $windowStart)
            ->sum('quantity_change');

        $events = InventoryLedger::where('vendor_id', $vendorId)
            ->where('product_id', $productId)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->orderBy('created_at')
            ->get(['quantity_change', 'created_at']);

        $balance = $startingBalance;
        $cursor = $windowStart;
        $outOfStockSeconds = 0;

        foreach ($events as $event) {
            if ($balance <= 0) {
                $outOfStockSeconds += $cursor->diffInSeconds($event->created_at);
            }

            $cursor = CarbonImmutable::parse($event->created_at);
            $balance += (int) $event->quantity_change;
        }

        if ($balance <= 0) {
            $outOfStockSeconds += $cursor->diffInSeconds($windowEnd);
        }

        return min($windowDays, (int) floor($outOfStockSeconds / 86400));
    }
}
