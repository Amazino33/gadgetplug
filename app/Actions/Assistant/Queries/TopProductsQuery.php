<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSaleItem;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TopProductsQuery extends BaseAssistantQuery
{
    /** Same gate as the Top Sellers report screen. */
    public function requiredPermission(): ?string
    {
        return 'view_inventory_reports';
    }

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        $hasAnySalesEver = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->exists()
            || OrderItemStoreAllocation::query()
                ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_item_store_allocations.store_id', $storeIds)
                ->where('orders.status', '!=', 'cancelled')
                ->whereNotNull('orders.revenue_recognized_at')
                ->exists();

        if (! $hasAnySalesEver) {
            return QueryResult::empty('no_sales_yet');
        }

        // 1. POS sales per product
        $posItems = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<', $to)
            ->selectRaw('pos_sale_items.product_id')
            ->selectRaw('COALESCE(SUM(pos_sale_items.total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(pos_sale_items.quantity), 0) as units')
            ->groupBy('pos_sale_items.product_id')
            ->get()
            ->keyBy('product_id');

        // 2. Online allocations per product
        $onlineItems = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->selectRaw('order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity * order_items.unit_price), 0) as revenue')
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity), 0) as units')
            ->groupBy('order_items.product_id')
            ->get()
            ->keyBy('product_id');

        $allProductIds = $posItems->keys()->merge($onlineItems->keys())->unique()->filter()->values();
        if ($allProductIds->isEmpty()) {
            return QueryResult::withData([
                'products' => [],
            ]);
        }

        $productNames = Product::whereIn('id', $allProductIds)->pluck('name', 'id');

        $combined = [];
        foreach ($allProductIds as $pId) {
            $pos = $posItems->get($pId);
            $online = $onlineItems->get($pId);

            $revNaira = (float) ($pos->revenue ?? 0) + (float) ($online->revenue ?? 0);
            $units = (int) ($pos->units ?? 0) + (int) ($online->units ?? 0);

            $combined[] = [
                'product_id' => (int) $pId,
                'name' => (string) ($productNames->get($pId) ?? 'Unknown Product'),
                'revenue' => (int) round($revNaira * 100),
                'units' => $units,
            ];
        }

        usort($combined, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return QueryResult::withData([
            'products' => array_slice($combined, 0, 5),
        ]);
    }
}
