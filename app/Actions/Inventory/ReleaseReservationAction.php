<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ReleaseReservationAction
{
    /**
     * Decrease reserved_stock on cancellation (before dispatch).
     * Physical stock_quantity is NOT touched — available_stock rises automatically.
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

            $product->decrement('reserved_stock', min($quantity, $product->reserved_stock));

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'product_id'       => $product->id,
                'user_id'          => $userId,
                'transaction_type' => 'reservation_released',
                'quantity_change'  => $quantity,
                'reference'        => $reference,
                'description'      => $description ?? 'Reservation released — order cancelled.',
            ]);
        });
    }
}
