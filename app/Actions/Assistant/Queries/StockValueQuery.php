<?php

declare(strict_types=1);

namespace App\Actions\Assistant\Queries;

use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class StockValueQuery extends BaseAssistantQuery
{
    public function supportsAggregate(): bool
    {
        return true;
    }

    protected function run(array $scope, CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $storeIds = $scope['resolved_store_ids'] ?? [];

        // Total units across selected stores
        $totalUnits = (int) ProductStoreStock::query()
            ->whereIn('store_id', $storeIds)
            ->sum('quantity');

        // Check stock cost layers first
        $layerCostNaira = (float) StockCostLayer::query()
            ->whereIn('store_id', $storeIds)
            ->where('quantity_remaining', '>', 0)
            ->sum(DB::raw('quantity_remaining * unit_cost'));

        if ($layerCostNaira <= 0) {
            $stockTable = (new ProductStoreStock)->getTable();
            // Fallback to product catalogue cost_price * store stock quantity
            $layerCostNaira = (float) ProductStoreStock::query()
                ->join('products', 'products.id', '=', "{$stockTable}.product_id")
                ->whereIn("{$stockTable}.store_id", $storeIds)
                ->where("{$stockTable}.quantity", '>', 0)
                ->sum(DB::raw("{$stockTable}.quantity * COALESCE(products.cost_price, 0)"));
        }

        return QueryResult::withData([
            'total_cost_value' => (int) round($layerCostNaira * 100),
            'total_units' => $totalUnits,
        ]);
    }
}
