<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\OrderItem;
use App\Models\OrderItemStoreAllocation;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreAllocator;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class ReserveStockAction
{
    /**
     * Increase reserved when an order is placed. Physical quantity is NOT
     * touched — available stock drops automatically.
     *
     * Availability is now measured across ALL of the vendor's active stores,
     * not just the default one. That is the intended behaviour change of this
     * phase: the storefront advertises the combined number, so checkout has to
     * be able to honour it. A vendor holding 5 in one branch and 3 in another
     * can sell 8.
     *
     * When $orderItemId is given the chosen stores are persisted as
     * allocations, and dispatch and release read them back rather than
     * re-deriving a store later. Passing an explicit $store keeps the old
     * single-store behaviour, for callers that already know the shelf.
     *
     * @throws \Exception when available stock is insufficient
     */
    public function execute(
        int     $productId,
        int     $quantity,
        ?string $reference   = null,
        ?string $description = null,
        ?int    $orderItemId = null,
        Store|int|null $store = null,
    ): InventoryLedger {
        return DB::transaction(function () use ($productId, $quantity, $reference, $description, $orderItemId, $store) {
            // The product row is locked first, exactly as every stock action
            // has always done: it serialises every writer of this product
            // across all its stores, so two checkouts cannot both read the
            // same availability and both reserve it.
            $product = Product::where('id', $productId)->lockForUpdate()->firstOrFail();

            $allocation = $store !== null
                ? [StoreStock::resolveStoreId($product, $store) => $quantity]
                : StoreAllocator::allocate($product->vendor_id, $product->id, $quantity);

            if ($allocation === []) {
                throw new \Exception("Insufficient available stock for: {$product->name}");
            }

            $ledger = null;

            foreach ($allocation as $storeId => $units) {
                $row = StoreStock::lockedRow($product, $storeId);

                // Re-checked per store under the lock. For the explicit-store
                // path this is the only guard; for the allocated path it is a
                // second look at what the allocator measured a moment ago.
                if (($row->quantity - $row->reserved) < $units) {
                    throw new \Exception("Insufficient available stock for: {$product->name}");
                }

                $row->reserved += $units;
                $row->save();

                if ($orderItemId !== null) {
                    // Additive on re-reserve of the same (line, store) rather
                    // than overwriting, so a partial top-up cannot silently
                    // shrink what is already held.
                    $existing = OrderItemStoreAllocation::where('order_item_id', $orderItemId)
                        ->where('store_id', $storeId)
                        ->first();

                    $existing
                        ? $existing->update(['quantity' => $existing->quantity + $units])
                        : OrderItemStoreAllocation::create([
                            'order_item_id' => $orderItemId,
                            'store_id'      => $storeId,
                            'quantity'      => $units,
                        ]);
                }

                // One ledger row per store touched, so the movement log says
                // which shelf the units were held on.
                $ledger = InventoryLedger::create([
                    'vendor_id'        => $product->vendor_id,
                    'store_id'         => $storeId,
                    'product_id'       => $product->id,
                    'user_id'          => null,
                    'transaction_type' => 'reserved',
                    'quantity_change'  => $units,
                    'reference'        => $reference,
                    'description'      => $description ?? 'Stock reserved for order.',
                ]);
            }

            // Stamped once, here, rather than by each of the three checkout
            // paths that call this action — this is the one place that always
            // runs when a hold is actually placed. whereNull guards a re-
            // reserve top-up on the same line from pushing the stale-
            // reservation clock back out.
            if ($orderItemId !== null) {
                $order = OrderItem::find($orderItemId)?->order;

                if ($order !== null && $order->reserved_at === null) {
                    $order->update(['reserved_at' => now()]);
                }
            }

            // The last store's row, preserving the previous single-row return
            // for callers that only ever check it is not an exception.
            return $ledger;
        });
    }
}
