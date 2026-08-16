<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductStoreStock;
use Illuminate\Support\Facades\DB;

// Keeps products.stock_quantity / products.reserved_stock equal to the sum of
// the product's per-store rows, always.
//
// Those two columns are no longer written by anybody — the five stock actions
// now move the per-store row and nothing else — so this is a pure projection,
// not a second source of truth that could disagree. It exists because roughly
// seventeen raw-SQL read sites (the Financial Report's inventory valuation,
// both dashboard widgets, the products table, the POS feed, the storefront
// catalogue) query those columns directly, and a PHP accessor cannot intercept
// raw SQL. Rewriting all of them at once, on those surfaces, is the risk this
// mirror avoids.
//
// Race safety rests on the product row, not on this class: every action locks
// products.id for update before touching a store row, so a concurrent write to
// a different store of the same product blocks until this recompute has
// committed. The lock here is the same one — re-taking it inside the caller's
// transaction is free, and it covers the case of a store row written outside
// the actions (a seed, a fixture, a future admin screen), which would
// otherwise recompute against a moving target.
class ProductStoreStockObserver
{
    public function saved(ProductStoreStock $row): void
    {
        $this->syncMirror((int) $row->product_id);
    }

    public function deleted(ProductStoreStock $row): void
    {
        $this->syncMirror((int) $row->product_id);
    }

    private function syncMirror(int $productId): void
    {
        // Nested inside the action's transaction this becomes a savepoint, so
        // the mirror and the movement that caused it commit or roll back
        // together. There is no window in which one exists without the other.
        DB::transaction(function () use ($productId) {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

            if (! $product) {
                return;
            }

            $totals = ProductStoreStock::query()
                ->where('product_id', $productId)
                ->selectRaw('COALESCE(SUM(quantity), 0) as total_quantity')
                ->selectRaw('COALESCE(SUM(reserved), 0) as total_reserved')
                ->first();

            // Through the model, not the query builder: Product logs
            // stock_quantity to the activity log, and writing around the model
            // would silently drop history that exists today.
            $product->update([
                'stock_quantity' => (int) $totals->total_quantity,
                'reserved_stock' => (int) $totals->total_reserved,
            ]);
        });
    }
}
