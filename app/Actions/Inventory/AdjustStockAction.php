<?php

namespace App\Actions\Inventory;

use App\Models\Inventory;
use App\Models\InventoryLedger;
use App\Models\Product;
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
    ) {
        return DB::transaction(function () use ($productId, $quantityChanged, $transactionType, $userId, $reference, $description) {
            // 1. PESSIMISTIC LOCK: Lock the product row until the transaction is done
            // If POS tries to sell this at the exact same millisecond, it will be forced to wait.
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            // 2. Prevent negative stock on sales
            if ($quantityChanged < 0 && $product->stock_quantity < abs($quantityChanged)) {
                throw new Exception('Insufficient stock for product: ' . $product->name);
            }

            // 3. Update the cache stock on the product table
            $product->stock_quantity += $quantityChanged;
            $product->save();

            // 4. Record the immutable movement in the ledger
            $ledger = InventoryLedger::create([
                'vendor_id' => $product->vendor_id,
                'product_id' => $product->id,
                'user_id' => $userId,
                'transaction_type' => $transactionType,
                'quantity_change' => $quantityChanged,
                'reference' => $reference,
                'description' => $description,
            ]);

            // 5. Trigger low stock alert if needed 
            if ($product->stock_quantity < 3) {
                // Event::dispatch(new LowStockAlert($product));
            }

            return $ledger;
        });
    }
}
