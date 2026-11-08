<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RevenueVsPreviousQuery extends BaseAssistantQuery
{
    /** Same gate as the Sales Report screen. */
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

        $currentKobo = $this->calculateRevenue($storeIds, $from, $to);

        $durationSeconds = $from->diffInSeconds($to);
        $prevTo = $from->copy();
        $prevFrom = $from->copy()->subSeconds($durationSeconds);

        $previousKobo = $this->calculateRevenue($storeIds, $prevFrom, $prevTo);

        if ($previousKobo === 0) {
            $percentageChange = $currentKobo > 0 ? 100.0 : 0.0;
        } else {
            $percentageChange = round((($currentKobo - $previousKobo) / $previousKobo) * 100, 2);
        }

        return QueryResult::withData([
            'current_revenue' => $currentKobo,
            'previous_revenue' => $previousKobo,
            'percentage_change' => $percentageChange,
        ]);
    }

    /**
     * @param  array<int>  $storeIds
     */
    private function calculateRevenue(array $storeIds, CarbonInterface $from, CarbonInterface $to): int
    {
        $posRevenue = (float) (PosSale::query()
            ->whereIn('pos_sales.store_id', $storeIds)
            ->where('pos_sales.status', '!=', 'voided')
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '>=', $from)
            ->where(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), '<', $to)
            ->sum(DB::raw('pos_sales.subtotal - pos_sales.discount_amount')) ?? 0);

        $onlineRevenue = (float) (OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_item_store_allocations.store_id', $storeIds)
            ->whereNotNull('orders.revenue_recognized_at')
            ->where('orders.revenue_recognized_at', '>=', $from)
            ->where('orders.revenue_recognized_at', '<', $to)
            ->where('orders.status', '!=', 'cancelled')
            ->sum(DB::raw('order_item_store_allocations.quantity * order_items.unit_price')) ?? 0);

        return (int) round(($posRevenue + $onlineRevenue) * 100);
    }
}
