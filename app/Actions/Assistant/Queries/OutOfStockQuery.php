<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\Product;
use App\Services\Assistant\ProductCatalogue;
use Carbon\CarbonInterface;

/**
 * Products with nothing left to sell at their own branch: on sale, stock of
 * our own (resold listings and services are never "out"), available stock
 * at or below zero.
 *
 * Phase 2: before this it looked at one store only and listed drafts,
 * archived products and resold listings alongside real gaps on the shelf.
 */
class OutOfStockQuery extends BaseAssistantQuery
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
        $query = ProductCatalogue::tracked(ProductCatalogue::query($scope['resolved_store_ids'] ?? []))
            ->with('store:id,name')
            ->whereRaw(ProductCatalogue::AVAILABLE_SQL.' <= 0');

        $total = (clone $query)->count();
        $rows = $query->orderBy('products.name')->limit(self::MAX_ITEMS)->get();

        return QueryResult::withData([
            'count' => $total,
            'showing' => $rows->count(),
            'items' => $rows->map(fn (Product $p) => [
                'name' => (string) $p->name,
                'store' => (string) ($p->store?->name ?? 'Unknown store'),
                'available' => 0,
            ])->all(),
        ]);
    }
}
