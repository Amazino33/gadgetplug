<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class ReserveStockAction
{
    /**
     * Increase reserved on the store row when an order is placed.
     * Physical quantity is NOT touched — available stock drops automatically.
     *
     * @throws \Exception when available stock is insufficient
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?string $reference   = null,
        ?string $description = null,
        Store|int|null $store = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $reference, $description, $store) {
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();
            $row = StoreStock::lockedRow($product, $store);

            // The availability guard now asks the store, not the vendor. A
            // customer cannot reserve from a store that has nothing on the
            // strength of stock sitting in another one.
            $available = $row->quantity - $row->reserved;

            if ($available < $quantity) {
                throw new \Exception("Insufficient available stock for: {$product->name}");
            }

            $row->reserved += $quantity;
            $row->save();

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'store_id'         => $row->store_id,
                'product_id'       => $product->id,
                'user_id'          => null,
                'transaction_type' => 'reserved',
                'quantity_change'  => $quantity,
                'reference'        => $reference,
                'description'      => $description ?? 'Stock reserved for order.',
            ]);
        });
    }
}
