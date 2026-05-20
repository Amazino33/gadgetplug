<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DispatchStockAction
{
    /**
     * Final deduction when an order is handed to logistics/rider.
     *
     * Math:
     *   physical stock_quantity  -=  quantity   (item leaves the shelf)
     *   reserved_stock           -=  quantity   (reservation fulfilled)
     *   available_stock stays the same (was already reduced when reserved)
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?int    $userId      = null,
        ?string $reference   = null,
        ?string $description = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $userId, $reference, $description) {
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            $product->decrement('stock_quantity', $quantity);
            $product->decrement('reserved_stock',  min($quantity, $product->reserved_stock));

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
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
