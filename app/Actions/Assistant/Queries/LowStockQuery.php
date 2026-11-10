<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\Product;
use App\Services\Assistant\ProductCatalogue;
use Carbon\CarbonInterface;

/**
 * Products running low: on sale, stock of our own, and at their own branch
 * more than nothing but less than the product's own low-stock level, the
 * rule behind the Products screen's badge and the dashboard's Low Stock tile.
 *
 * Phase 2: before this, it answered "not set up" unless the model sent a
 * threshold, looked at one store only, counted drafts and resold listings,
 * and dropped half units.
 */
class LowStockQuery extends BaseAssistantQuery
{
    private const MAX_ITEMS = 25;

    /** Same gate as the Products screen. */
    public function requiredPermission(): ?string
    {
        return 'view_any_products';
    }

    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        // An explicit "below 10" overrides each product's own level.
        $override = isset($options['threshold']) && is_numeric($options['threshold']) ? (int) $options['threshold'] : null;
        $available = ProductCatalogue::AVAILABLE_SQL;

        $query = ProductCatalogue::tracked(ProductCatalogue::query($scope['resolved_store_ids'] ?? []))
            ->with('store:id,name')
            ->whereRaw("{$available} > 0");

        $override !== null
            ? $query->whereRaw("{$available} < ?", [$override])
            : $query->where('products.low_stock_threshold', '>', 0)->whereRaw("{$available} < products.low_stock_threshold");

        $total = (clone $query)->count();
        $rows = $query->orderByRaw("{$available} asc")->orderBy('products.name')->limit(self::MAX_ITEMS)->get();

        return QueryResult::withData([
            'threshold' => $override ?? 'each product\'s own low-stock level',
            'count' => $total,
            'showing' => $rows->count(),
            'items' => $rows->map(fn (Product $p) => [
                'name' => (string) $p->name,
                'store' => (string) ($p->store?->name ?? 'Unknown store'),
                'available' => ProductCatalogue::available($p),
                'low_stock_level' => $override ?? (int) $p->low_stock_threshold,
            ])->all(),
        ]);
    }
}
