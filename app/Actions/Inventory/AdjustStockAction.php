<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;
use Exception;

class AdjustStockAction
{
    /**
     * @throws Exception
     */
    public function execute(
        int $productId,
        int $quantityChanged,
        string $transactionType,
        ?int $userId = null,
        ?string $reference = null,
        ?string $description = null,
        ?int $auditSessionId = null,
        ?string $reasonCode = null,
        Store|int|null $store = null,
    ) {
        return DB::transaction(function () use ($productId, $quantityChanged, $transactionType, $userId, $reference, $description, $auditSessionId, $reasonCode, $store) {
            // 1. PESSIMISTIC LOCK: Lock the product row until the transaction is done.
            // If POS tries to sell this at the exact same millisecond, it will be forced to wait.
            // Still the product row and not merely the store row: it is what
            // serialises every writer of this product across all its stores, so
            // the mirror recompute below cannot read a half-written total.
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // 2. The row this movement actually lands on.
            $row = StoreStock::lockedRow($product, $store);

            // 3. Prevent negative stock on sales — now against what this store
            // holds, not the vendor-wide total. Selling from a store that has
            // none must fail even when another store is full.
            if ($quantityChanged < 0 && $row->quantity < abs($quantityChanged)) {
                throw new Exception('Insufficient stock for product: ' . $product->name);
            }

            // 4. Move the stock. products.stock_quantity is not touched here —
            // ProductStoreStockObserver derives it from this row.
            $row->quantity += $quantityChanged;
            $row->save();

            // 5. Record the immutable movement in the ledger
            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'store_id'         => $row->store_id,
                'product_id'       => $product->id,
                'user_id'          => $userId,
                'transaction_type' => $transactionType,
                'quantity_change'  => $quantityChanged,
                'reference'        => $reference,
                'description'      => $description,
                'audit_session_id' => $auditSessionId,
                'reason_code'      => $reasonCode,
            ]);
        });
    }
}
