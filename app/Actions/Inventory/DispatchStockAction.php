<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class DispatchStockAction
{
    /**
     * Final deduction when an order is handed to logistics/rider.
     *
     * Math, now on the store row rather than the product:
     *   quantity  -=  quantity   (item leaves that store's shelf)
     *   reserved  -=  quantity   (reservation fulfilled)
     *   available stays the same (was already reduced when reserved)
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?int    $userId      = null,
        ?string $reference   = null,
        ?string $description = null,
        Store|int|null $store = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $userId, $reference, $description, $store) {
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();
            $row = StoreStock::lockedRow($product, $store);

            // min() preserved exactly as it was: a dispatch larger than what is
            // reserved clears the reservation rather than driving it negative.
            $row->quantity -= $quantity;
            $row->reserved -= min($quantity, $row->reserved);
            $row->save();

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'store_id'         => $row->store_id,
                'product_id'       => $product->id,
                'user_id'          => $userId,
                'transaction_type' => 'dispatched',
                'quantity_change'  => -$quantity,
                'reference'        => $reference,
                'description'      => $description ?? 'Physical deduction on dispatch to rider.',
            ]);
        });
    }
}
