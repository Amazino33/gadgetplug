<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use App\Models\Store;
use App\Services\Assistant\ReportedSales;
use Carbon\CarbonInterface;

/**
 * Revenue and orders — the dashboard's figure (SalesReportService::summary),
 * net of discounts and refunds, excluding VAT. See ReportedSales.
 */
class SalesSummaryQuery extends BaseAssistantQuery
{
    /** Same gate as the Sales Report / dashboard sales screen. */
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

        // Check if store < 1 day old AND zero orders ever
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
            ...self::naira('revenue', $s['revenue']),
            'orders' => $s['orders'],
            'units' => $s['units'],
            ...self::naira('pos_revenue', $s['pos_revenue']),
            ...self::naira('online_revenue', $s['online_revenue']),
        ]);
    }
}
