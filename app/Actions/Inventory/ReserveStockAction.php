<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ReserveStockAction
{
    /**
     * Increase reserved_stock when an order is placed.
     * Physical stock_quantity is NOT touched — available_stock drops automatically.
     *
     * @throws \Exception when available stock is insufficient
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?string $reference   = null,
        ?string $description = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $reference, $description) {
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            $available = $product->stock_quantity - $product->reserved_stock;

            if ($available < $quantity) {
                throw new \Exception("Insufficient available stock for: {$product->name}");
            }

            $product->increment('reserved_stock', $quantity);

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
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
