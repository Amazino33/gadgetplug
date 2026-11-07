<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\ProductStoreStock;
use Carbon\CarbonInterface;

class LowStockQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        if (! isset($options['threshold']) || ! is_numeric($options['threshold'])) {
            return QueryResult::empty('not_configured');
        }

        $threshold = (int) $options['threshold'];
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $lowStockRows = ProductStoreStock::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->whereRaw('(quantity - reserved) <= ?', [$threshold])
            ->whereRaw('(quantity - reserved) > 0')
            ->orderByRaw('(quantity - reserved) ASC')
            ->get();

        $items = [];
        foreach ($lowStockRows as $row) {
            $effectiveQty = max(0, (int) ($row->quantity - $row->reserved));
            $items[] = [
                'product_id' => (int) $row->product_id,
                'name' => (string) ($row->product?->name ?? 'Unknown Product'),
                'quantity' => $effectiveQty,
            ];
        }

        return QueryResult::withData([
            'threshold' => $threshold,
            'items' => $items,
        ]);
    }
}
