<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSaleItem;
use App\Models\ProductStoreStock;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SlowProductsQuery extends BaseAssistantQuery
{
    /** Same gate as the Inventory reports screen. */
    public function requiredPermission(): ?string
    {
        return 'view_inventory_reports';
    }

    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));
        $store = Store::query()->find($storeId);

        if (! $store || $store->created_at->diffInDays(now()) < 7) {
            return QueryResult::empty('store_too_new');
        }

        // Get all products currently stocked in this store
        $stockRecords = ProductStoreStock::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->get();

        if ($stockRecords->isEmpty()) {
            return QueryResult::withData([
                'store_too_new' => false,
                'products' => [],
            ]);
        }

        // POS units sold
        $posUnits = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.store_id', $storeId)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<', $to)
            ->groupBy('pos_sale_items.product_id')
            ->selectRaw('pos_sale_items.product_id, COALESCE(SUM(pos_sale_items.quantity), 0) as units')
            ->pluck('units', 'product_id');

        // Online units sold
        $onlineUnits = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_item_store_allocations.store_id', $storeId)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, COALESCE(SUM(order_item_store_allocations.quantity), 0) as units')
            ->pluck('units', 'product_id');

        $productList = [];
        foreach ($stockRecords as $stock) {
            $pId = $stock->product_id;
            $sold = (int) ($posUnits->get($pId) ?? 0) + (int) ($onlineUnits->get($pId) ?? 0);

            $productList[] = [
                'product_id' => (int) $pId,
                'name' => (string) ($stock->product?->name ?? 'Unknown Product'),
                'units' => $sold,
            ];
        }

        usort($productList, fn ($a, $b) => $a['units'] <=> $b['units']);

        return QueryResult::withData([
            'store_too_new' => false,
            'products' => array_slice($productList, 0, 5),
        ]);
    }
}
