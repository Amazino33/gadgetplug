<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Services\Assistant\ReportedSales;
use Carbon\CarbonInterface;

/**
 * This period's revenue against the period of the same length just before
 * it, both the dashboard's figure (SalesReportService::summary). The change
 * is worked out here, never by the model.
 */
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
        $sales = app(ReportedSales::class);

        $current = $sales->summary($storeIds, $from, $to)['revenue'];

        $durationSeconds = $from->diffInSeconds($to);
        $prevTo = $from->copy();
        $prevFrom = $from->copy()->subSeconds($durationSeconds);

        $previous = $sales->summary($storeIds, $prevFrom, $prevTo)['revenue'];

        $percentageChange = $previous == 0.0
            ? ($current > 0 ? 100.0 : 0.0)
            : (($current - $previous) / $previous) * 100;

        return QueryResult::withData([
            ...self::naira('current_revenue', $current),
            ...self::naira('previous_revenue', $previous),
            ...self::naira('difference', $current - $previous),
            ...self::percent('percentage_change', $percentageChange),
            'previous_from' => $prevFrom->toDateString(),
            'previous_to' => $prevTo->toDateString(),
        ]);
    }
}
