<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Services\ActiveStore;
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
    /**
     * Every product gets a home store before it is ever written.
     *
     * Home store decides which branch's inventory, count sheet and till a
     * product appears in, so a product without one is invisible everywhere —
     * not an edge case but a product nobody can sell. Only the panel form sets
     * it explicitly, and it is far from the only way a product gets created:
     * seeders, imports, factories and tests all call Product::create()
     * directly. Filling it here rather than in the form means the invariant
     * holds for all of them.
     *
     * The branch being worked in wins, but only if it belongs to this
     * product's own vendor — an active store from another tenant would
     * otherwise home the product in someone else's business.
     */
    public function creating(Product $product): void
    {
        if ($product->store_id !== null) {
            return;
        }

        $active = ActiveStore::currentId();

        $product->store_id = ($active !== null && Store::query()
            ->whereKey($active)
            ->where('vendor_id', $product->vendor_id)
            ->exists())
                ? $active
                : DefaultStore::seedFor($product->vendor)->id;
    }

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
