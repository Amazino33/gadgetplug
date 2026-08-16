<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\OrderItemStoreAllocation;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class ReleaseReservationAction
{
    /**
     * Decrease reserved on cancellation (before dispatch). Physical quantity
     * is NOT touched — available stock rises automatically.
     *
     * Releases against the stores the units were actually held at, read from
     * the line's allocations, then clears those allocations so a later
     * re-reservation of the same line allocates fresh rather than inheriting a
     * stale split.
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?int    $userId      = null,
        ?string $reference   = null,
        ?string $description = null,
        ?int    $orderItemId = null,
        Store|int|null $store = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $userId, $reference, $description, $orderItemId, $store) {
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            $allocations = ($store === null && $orderItemId !== null)
                ? OrderItemStoreAllocation::where('order_item_id', $orderItemId)->orderBy('store_id')->get()
                : collect();

            $plan = $allocations->isNotEmpty()
                ? $allocations->pluck('quantity', 'store_id')->map(fn ($q) => (int) $q)->all()
                : [StoreStock::resolveStoreId($product, $store) => $quantity];

            $ledger = null;

            foreach ($plan as $storeId => $units) {
                $row = StoreStock::lockedRow($product, $storeId);

                // Same clamp as always — releasing more than is held cannot
                // push a reservation below zero, which keeps a double
                // cancellation idempotent rather than destructive.
                $row->reserved -= min($units, $row->reserved);
                $row->save();

                $ledger = InventoryLedger::create([
                    'vendor_id'        => $product->vendor_id,
                    'store_id'         => $storeId,
                    'product_id'       => $product->id,
                    'user_id'          => $userId,
                    'transaction_type' => 'reservation_released',
                    'quantity_change'  => $units,
                    'reference'        => $reference,
                    'description'      => $description ?? 'Reservation released — order cancelled.',
                ]);
            }

            // Cleared only on the allocation-driven path: the line no longer
            // holds anything anywhere, so leaving the rows would misreport it
            // as still supplied by those stores. A second release then finds
            // nothing to release and changes no stock, which is what makes
            // repeating it harmless.
            if ($allocations->isNotEmpty()) {
                OrderItemStoreAllocation::where('order_item_id', $orderItemId)->delete();
            }

            return $ledger;
        });
    }
}
