<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLedger;
use App\Models\OrderItemStoreAllocation;
use App\Models\Product;
use App\Models\Store;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class DispatchStockAction
{
    /**
     * Final deduction when an order is handed to logistics/rider.
     *
     * Math per allocated store:
     *   quantity -= units   (they leave that branch's shelf)
     *   reserved -= units   (that branch's reservation is fulfilled)
     *
     * The stores come from the allocations written at reservation, never from
     * is_default: re-deriving here is what allowed a reservation held at one
     * branch to be dispatched out of another after the default changed,
     * stranding the first branch's reservation forever.
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

            $plan = $this->resolvePlan($product, $quantity, $orderItemId, $store);

            $ledger = null;

            foreach ($plan as $storeId => $units) {
                $row = StoreStock::lockedRow($product, $storeId);

                // min() preserved exactly as before: dispatching more than is
                // reserved clears the reservation rather than driving it
                // negative. Physical quantity is allowed to go negative for
                // the same reason it always was — the goods have left.
                $row->quantity -= $units;
                $row->reserved -= min($units, $row->reserved);
                $row->save();

                $ledger = InventoryLedger::create([
                    'vendor_id'        => $product->vendor_id,
                    'store_id'         => $storeId,
                    'product_id'       => $product->id,
                    'user_id'          => $userId,
                    'transaction_type' => 'dispatched',
                    'quantity_change'  => -$units,
                    'reference'        => $reference,
                    'description'      => $description ?? 'Physical deduction on dispatch to rider.',
                ]);
            }

            return $ledger;
        });
    }

    /**
     * @return array<int, int> store id => units
     */
    private function resolvePlan(Product $product, int $quantity, ?int $orderItemId, Store|int|null $store): array
    {
        if ($store !== null) {
            return [StoreStock::resolveStoreId($product, $store) => $quantity];
        }

        if ($orderItemId !== null) {
            $allocations = OrderItemStoreAllocation::where('order_item_id', $orderItemId)
                ->orderBy('store_id')
                ->pluck('quantity', 'store_id')
                ->map(fn ($q) => (int) $q)
                ->all();

            if ($allocations !== []) {
                return $allocations;
            }
        }

        // No allocation to read — an order line from before this phase whose
        // backfill did not reach it, or a caller with no line at all. Falls
        // back to the vendor's default store, which is exactly where such an
        // order's stock was reserved under the old behaviour.
        return [StoreStock::resolveStoreId($product) => $quantity];
    }
}
