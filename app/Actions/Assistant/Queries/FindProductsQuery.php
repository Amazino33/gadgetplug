<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Services\Assistant\ProductCatalogue;
use Carbon\CarbonInterface;

/**
 * "How much is the 20000mAh power bank?", "do we have Redmi 15C?", "list
 * the power banks we have": products by SKU, barcode or name, with price and
 * stock per branch (ProductCatalogue). The same item at two branches comes
 * back as one product with a row per branch.
 *
 * Gated like the Products screen. Cost is in the rows and is removed for
 * anyone without view_cost_price, as everywhere else.
 */
class FindProductsQuery extends BaseAssistantQuery
{
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
        $term = trim((string) ($options['query'] ?? ''));

        if ($term === '') {
            return QueryResult::empty('not_configured');
        }

        $inStockOnly = filter_var($options['in_stock_only'] ?? false, FILTER_VALIDATE_BOOL);
        $found = ProductCatalogue::find($scope['resolved_store_ids'] ?? [], $term, $inStockOnly);

        return QueryResult::withData([
            'searched_for' => $term,
            'in_stock_only' => $inStockOnly,
            // exact_code (SKU or barcode), name (every word found), possible (loose: ask
            // which one before answering about it), none.
            'match' => $found['match'],
            'products_found' => $found['total'],
            'showing' => count($found['groups']),
            'products' => $found['groups'],
        ]);
    }
}
