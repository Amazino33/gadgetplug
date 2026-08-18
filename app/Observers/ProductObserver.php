<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Services\DefaultStore;

// Gives a newly created product its opening stock row.
//
// Setting products.stock_quantity at creation time is how starting stock has
// always been expressed in this codebase — seeders, factories, fixtures, and
// any future import all do it. Once the per-store rows became the source of
// truth that convention quietly stopped meaning anything: the column said ten,
// the store row said nothing, and the first sale counted down from zero.
//
// So the column keeps its old meaning at exactly one moment — creation — and
// is translated here into the row that now owns it. Afterwards it is a pure
// mirror again, maintained by ProductStoreStockObserver, and writing to it
// directly does nothing.
class ProductObserver
{
    public function created(Product $product): void
    {
        $quantity = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_stock ?? 0);

        // Idempotent by (product, store): the Phase 2a backfill and the 2b
        // re-sync both write this same row, and neither may collide with this.
        $existing = ProductStoreStock::query()
            ->where('product_id', $product->id)
            ->exists();

        if ($existing) {
            return;
        }

        // The opening stock belongs at the product's home store. Falling back
        // to the vendor default only when no home was named — otherwise a
        // product homed at one branch would have its stock created at another,
        // and identity and quantity would disagree from the first moment.
        //
        // seedFor rather than a bare lookup for that fallback: a vendor with no
        // default store would otherwise make product creation throw, and
        // refusing to create a product because of a missing store is a worse
        // failure than simply creating the store it should already have had.
        $storeId = $product->store_id ?? DefaultStore::seedFor($product->vendor)->id;

        ProductStoreStock::create([
            'product_id' => $product->id,
            'store_id'   => $storeId,
            'quantity'   => $quantity,
            'reserved'   => $reserved,
        ]);
    }
}
