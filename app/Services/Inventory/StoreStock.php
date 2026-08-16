<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use RuntimeException;

// Where the five stock actions get the row they are about to move.
//
// Locking discipline, unchanged in spirit from what the actions have always
// done: the caller locks the PRODUCT row first, then asks for the store row
// here. The product-row lock is what makes the mirror safe — two writers
// touching different stores of the same product still serialise on that one
// row, so neither can sum the store rows while the other is mid-write.
// Callers that skip the product lock get a correct row but no such guarantee.
class StoreStock
{
    /**
     * The store a movement belongs to: whatever the caller named, else the
     * product's vendor's default store. Every current caller passes nothing —
     * the active-store concept arrives in a later phase — so in practice this
     * is the default store, which is exactly where their stock already sits.
     */
    public static function resolveStoreId(Product $product, Store|int|null $store = null): int
    {
        if ($store instanceof Store) {
            return $store->id;
        }

        if (is_int($store)) {
            return $store;
        }

        $storeId = Store::query()
            ->where('vendor_id', $product->vendor_id)
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        if ($storeId === null) {
            throw new RuntimeException(
                "Vendor {$product->vendor_id} has no default store, so stock for product {$product->id} has nowhere to move."
            );
        }

        return (int) $storeId;
    }

    /**
     * The (product, store) row, locked for update and created at zero if this
     * product has never held stock in this store. Creating it is not a stock
     * movement — it starts empty and the caller's own mutation is what moves
     * anything.
     */
    public static function lockedRow(Product $product, Store|int|null $store = null): ProductStoreStock
    {
        $storeId = self::resolveStoreId($product, $store);

        $row = ProductStoreStock::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        return ProductStoreStock::create([
            'product_id' => $product->id,
            'store_id'   => $storeId,
            'quantity'   => 0,
            'reserved'   => 0,
        ]);
    }
}
