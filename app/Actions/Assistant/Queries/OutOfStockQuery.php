<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\ProductStoreStock;
use Carbon\CarbonInterface;

class OutOfStockQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return false;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeId = (int) ($scope['store_id'] ?? ($scope['resolved_store_ids'][0] ?? 0));

        $outRows = ProductStoreStock::query()
            ->with('product')
            ->where('store_id', $storeId)
            ->whereRaw('(quantity - reserved) <= 0')
            ->get();

        $items = [];
        foreach ($outRows as $row) {
            $items[] = [
                'product_id' => (int) $row->product_id,
                'name' => (string) ($row->product?->name ?? 'Unknown Product'),
                'quantity' => 0,
            ];
        }

        return QueryResult::withData([
            'items' => $items,
            'count' => count($items),
        ]);
    }
}
