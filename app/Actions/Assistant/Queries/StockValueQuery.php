<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Services\Inventory\StoreStockMetrics;
use Carbon\CarbonInterface;

/**
 * Stock on hand at cost and at selling price — StoreStockMetrics, the figures
 * the Stores overview, the Store Selector and Checkmate show for a branch.
 * Valued at the product's current cost price; products with no cost price
 * count as nothing and are reported, so a low total is never presented as
 * complete.
 */
class StockValueQuery extends BaseAssistantQuery
{
    /** Same gate as the Inventory page stock value, and cost visibility screen. */
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
        $metrics = StoreStockMetrics::forStores($storeIds);

        return QueryResult::withData([
            ...self::naira('total_cost_value', $metrics->sum('cost_value')),
            ...self::naira('total_retail_value', $metrics->sum('retail_value')),
            'total_units' => $metrics->sum('units'),
            'products_without_cost_price' => (int) $metrics->sum('missing_cost_count'),
        ]);
    }
}
