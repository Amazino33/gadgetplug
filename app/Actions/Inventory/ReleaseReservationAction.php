<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class ReleaseReservationAction
{
    /**
     * Decrease reserved on the store row on cancellation (before dispatch).
     * Physical quantity is NOT touched — available stock rises automatically.
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

            // Same min() clamp as before — releasing more than is held cannot
            // push the reservation below zero.
            $row->reserved -= min($quantity, $row->reserved);
            $row->save();

            return InventoryLedger::create([
                'vendor_id'        => $product->vendor_id,
                'store_id'         => $row->store_id,
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
