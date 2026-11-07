<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSaleItem;
use App\Models\ProductStoreStock;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class DeadStockQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));
        $store = Store::query()->find($storeId);

        if (! $store || $store->created_at->diffInDays(now()) < 30) {
            return QueryResult::empty('store_too_new');
        }

        $thirtyDaysAgo = now()->subDays(30);

        // Active stock held in store
        $stocks = ProductStoreStock::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->where('quantity', '>', 0)
            ->get();

        if ($stocks->isEmpty()) {
            return QueryResult::withData([
                'store_too_new' => false,
                'items' => [],
            ]);
        }

        $productIds = $stocks->pluck('product_id')->all();

        // Sold in POS in last 30 days
        $soldPos = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.store_id', $storeId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereIn('pos_sale_items.product_id', $productIds)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $thirtyDaysAgo)
            ->pluck('pos_sale_items.product_id')
            ->all();

        // Sold online in last 30 days
        $soldOnline = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_store_allocations.store_id', $storeId)
            ->whereIn('order_items.product_id', $productIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $thirtyDaysAgo)
            ->where('orders.status', '!=', 'cancelled')
            ->pluck('order_items.product_id')
            ->all();

        $activeProductIds = array_unique(array_merge($soldPos, $soldOnline));

        $deadItems = [];
        foreach ($stocks as $stock) {
            if (! in_array($stock->product_id, $activeProductIds, true)) {
                $deadItems[] = [
                    'product_id' => (int) $stock->product_id,
                    'name' => (string) ($stock->product?->name ?? 'Unknown Product'),
                    'quantity' => (int) $stock->quantity,
                ];
            }
        }

        return QueryResult::withData([
            'store_too_new' => false,
            'items' => $deadItems,
        ]);
    }
}
