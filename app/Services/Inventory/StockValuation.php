<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use Illuminate\Support\Facades\DB;

// What the stock on hand is worth, from the batches it actually arrived in.
//
// Reconcile-on-read rather than trusting the layers alone. Stock can reach a
// branch without passing through a stock action — a seeded fixture, a bulk
// import, a row written by hand — and those units have no layer behind them.
// Valuing them at zero would understate the total badly and silently, so any
// quantity a product holds beyond what its layers account for is valued at the
// product's current cost_price, which is exactly what the old whole-catalogue
// calculation did. The layers improve on that figure wherever they exist and
// never make it worse.
//
// The reverse case — layers holding more than the stock row — is ignored on
// purpose. It would mean the invariant has broken, and inventing value from it
// is the wrong response; the units genuinely on the shelf are the ceiling.
class StockValuation
{
    /**
     * @return array{value: float, uncosted_units: int, uncosted_product_count: int}
     */
    public static function forVendor(int $vendorId): array
    {
        $layers = StockCostLayer::query()
            ->selectRaw('product_id, store_id')
            ->selectRaw('SUM(quantity_remaining) as layer_qty')
            ->selectRaw('SUM(quantity_remaining * COALESCE(unit_cost, 0)) as layer_value')
            ->selectRaw('SUM(CASE WHEN unit_cost IS NULL THEN quantity_remaining ELSE 0 END) as layer_uncosted')
            ->where('quantity_remaining', '>', 0)
            ->groupBy('product_id', 'store_id');

        // Read off the model rather than written out: this table is
        // deliberately named in the singular, and hardcoding it here is exactly
        // how that gets out of step.
        $stock = (new ProductStoreStock)->getTable();

        // CASE rather than GREATEST/MAX: the two engines spell that function
        // differently, and this runs on MySQL in production and SQLite in the
        // test suite.
        $shortfall = "CASE WHEN {$stock}.quantity > COALESCE(l.layer_qty, 0)"
            . " THEN {$stock}.quantity - COALESCE(l.layer_qty, 0) ELSE 0 END";

        $row = ProductStoreStock::query()
            ->join('products', 'products.id', '=', "{$stock}.product_id")
            ->leftJoinSub($layers, 'l', function ($join) use ($stock) {
                $join->on('l.product_id', '=', "{$stock}.product_id")
                    ->on('l.store_id', '=', "{$stock}.store_id");
            })
            ->where('products.vendor_id', $vendorId)
            ->where("{$stock}.quantity", '>', 0)
            ->selectRaw("COALESCE(SUM(COALESCE(l.layer_value, 0) + ({$shortfall}) * COALESCE(products.cost_price, 0)), 0) as value")
            ->selectRaw("COALESCE(SUM(COALESCE(l.layer_uncosted, 0) + CASE WHEN products.cost_price IS NULL THEN ({$shortfall}) ELSE 0 END), 0) as uncosted_units")
            ->selectRaw("COUNT(DISTINCT CASE WHEN COALESCE(l.layer_uncosted, 0) > 0 OR (products.cost_price IS NULL AND ({$shortfall}) > 0) THEN products.id END) as uncosted_products")
            ->first();

        return [
            'value'                  => round((float) $row->value, 2),
            'uncosted_units'         => (int) $row->uncosted_units,
            'uncosted_product_count' => (int) $row->uncosted_products,
        ];
    }
}
