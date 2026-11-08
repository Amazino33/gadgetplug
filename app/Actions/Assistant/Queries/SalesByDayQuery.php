<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Exceptions\Assistant\DateRangeTooLarge;
use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use App\Models\Store;
use App\Services\Assistant\ReportedSales;
use Carbon\CarbonInterface;

/**
 * Revenue and orders per business day — one dashboard summary per day
 * (ReportedSales::byDay), so each day matches the dashboard's own figure.
 */
class SalesByDayQuery extends BaseAssistantQuery
{
    /** One summary per day: a quarter at a time keeps it to a few hundred queries. */
    public const MAX_DAYS = 92;

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

        // Check if store < 1 day old AND zero orders ever
        $stores = Store::query()->whereIn('id', $storeIds)->get();
        if ($stores->isNotEmpty() && $stores->every(fn (Store $s) => $s->created_at >= now()->subHours(24))) {
            $hasEverHadPos = PosSale::query()->whereIn('store_id', $storeIds)->exists();
            $hasEverHadOnline = OrderItemStoreAllocation::query()->whereIn('store_id', $storeIds)->exists();
            if (! $hasEverHadPos && ! $hasEverHadOnline) {
                return QueryResult::empty('store_too_new');
            }
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new DateRangeTooLarge('sales_by_day covers up to '.self::MAX_DAYS.' days at a time; use sales_summary for longer periods.');
        }

        // Days with nothing sold are left out, as before: a 30-day answer
        // should not read out twenty zeros.
        $days = collect(app(ReportedSales::class)->byDay($storeIds, $from, $to))
            ->filter(fn (array $d) => $d['orders'] > 0 || $d['revenue'] != 0.0)
            ->map(fn (array $d) => ['date' => $d['date'], ...self::naira('revenue', $d['revenue']), 'orders' => $d['orders']])
            ->values()
            ->all();

        return QueryResult::withData([
            'days' => $days,
        ]);
    }
}
