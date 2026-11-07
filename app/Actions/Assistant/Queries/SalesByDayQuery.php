<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SalesByDayQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        // Check if store < 1 day old AND zero orders ever
        $stores = Store::query()->whereIn('id', $storeIds)->get();
        if ($stores->isNotEmpty() && $stores->every(fn (Store $s) => $s->created_at >= now()->subHours(24))) {
            $hasEverHadPos = PosSale::query()->whereIn('store_id', $storeIds)->exists();
            $hasEverHadOnline = OrderItemStoreAllocation::query()->whereIn('store_id', $storeIds)->exists();
            if (! $hasEverHadPos && ! $hasEverHadOnline) {
                return QueryResult::empty('store_too_new');
            }
        }

        // 1. Grouped POS sales by day
        $posDays = PosSale::query()
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<=', $to)
            ->selectRaw('DATE(COALESCE(pos_sales.completed_at, pos_sales.created_at)) as day')
            ->selectRaw('COALESCE(SUM(pos_sales.subtotal - pos_sales.discount_amount), 0) as revenue')
            ->selectRaw('COUNT(*) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // 2. Grouped Online sales by day
        $onlineDays = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<=', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->selectRaw('DATE(orders.revenue_recognized_at) as day')
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity * order_items.unit_price), 0) as revenue')
            ->selectRaw('COUNT(DISTINCT orders.id) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $allDates = $posDays->keys()->merge($onlineDays->keys())->unique()->sort()->values();

        $days = [];
        foreach ($allDates as $dateStr) {
            $pos = $posDays->get($dateStr);
            $online = $onlineDays->get($dateStr);

            $revenueNaira = (float) ($pos->revenue ?? 0) + (float) ($online->revenue ?? 0);
            $orders = (int) ($pos->orders ?? 0) + (int) ($online->orders ?? 0);

            $days[] = [
                'date' => (string) $dateStr,
                'revenue' => (int) round($revenueNaira * 100),
                'orders' => $orders,
            ];
        }

        return QueryResult::withData([
            'days' => $days,
        ]);
    }
}
