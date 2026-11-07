<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ProfitQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        // Check if store < 1 day old and zero orders ever
        $stores = Store::query()->whereIn('id', $storeIds)->get();
        if ($stores->isNotEmpty() && $stores->every(fn (Store $s) => $s->created_at >= now()->subHours(24))) {
            $hasEverHadPos = PosSale::query()->whereIn('store_id', $storeIds)->exists();
            $hasEverHadOnline = OrderItemStoreAllocation::query()->whereIn('store_id', $storeIds)->exists();
            if (! $hasEverHadPos && ! $hasEverHadOnline) {
                return QueryResult::empty('store_too_new');
            }
        }

        // 1. POS sales revenue: subtotal minus discounts for non-voided sales
        $posRevenueRow = PosSale::query()
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<=', $to)
            ->selectRaw('COALESCE(SUM(pos_sales.subtotal - pos_sales.discount_amount), 0) as revenue')
            ->first();

        // 2. POS sales cost: use cost_total when available, fallback to quantity * (unit_cost ?? products.cost_price ?? 0)
        $posCostRow = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->leftJoin('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<=', $to)
            ->selectRaw('COALESCE(SUM(COALESCE(pos_sale_items.cost_total, pos_sale_items.quantity * COALESCE(pos_sale_items.unit_cost, products.cost_price, 0))), 0) as cost')
            ->first();

        // 3. Online orders revenue allocated to these stores
        $onlineRevenueRow = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<=', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity * order_items.unit_price), 0) as revenue')
            ->first();

        // 4. Online orders cost: cost_total with fallback to quantity * (unit_cost ?? products.cost_price ?? 0)
        $onlineCostRow = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<=', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(COALESCE(order_items.cost_total, order_item_store_allocations.quantity * COALESCE(order_items.unit_cost, products.cost_price, 0))), 0) as cost')
            ->first();

        $totalRevenueNaira = (float) ($posRevenueRow->revenue ?? 0) + (float) ($onlineRevenueRow->revenue ?? 0);
        $totalCostNaira = (float) ($posCostRow->cost ?? 0) + (float) ($onlineCostRow->cost ?? 0);

        $totalRevenueKobo = (int) round($totalRevenueNaira * 100);
        $totalCostKobo = (int) round($totalCostNaira * 100);
        $profitKobo = $totalRevenueKobo - $totalCostKobo;

        return QueryResult::withData([
            'total_revenue' => $totalRevenueKobo,
            'total_cost' => $totalCostKobo,
            'profit' => $profitKobo,
        ]);
    }
}
