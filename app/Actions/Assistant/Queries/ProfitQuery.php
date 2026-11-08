<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use App\Models\Store;
use App\Services\Assistant\ReportedSales;
use Carbon\CarbonInterface;

/**
 * Revenue, cost of goods sold, profit and margin — the dashboard's figures
 * (SalesReportService::summary): revenue net of discounts and refunds, cost
 * as booked at the time of sale.
 */
class ProfitQuery extends BaseAssistantQuery
{
    /** Same gate as the Sales Report, and cost visibility screen. */
    public function requiredPermission(): ?string
    {
        return 'view_inventory_reports';
    }

    /** The answer is cost or profit: refused without view_cost_price. */
    public function costSensitive(): bool
    {
        return true;
    }

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

        $s = app(ReportedSales::class)->summary($storeIds, $from, $to);

        return QueryResult::withData([
            ...self::naira('total_revenue', $s['revenue']),
            ...self::naira('total_cost', $s['cost']),
            ...self::naira('profit', $s['profit']),
            ...self::percent('margin_percent', $s['margin']),
            // Some sold lines had no cost recorded at the time of sale, so their
            // cost is today's cost price: the profit is an estimate.
            'cost_is_estimated' => $s['cost_is_estimated'],
        ]);
    }
}
