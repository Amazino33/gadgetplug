<?php

namespace App\Actions\Procurement;

use App\Models\InventoryLedger;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Services\Inventory\StockCostLayers;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApproveProcurementAction
{
    /**
     * $store is a fallback only, for orders raised before a procurement
     * carried its own destination. The order's own store_id wins whenever it
     * has one — the branch is a decision made when the order is raised, not a
     * side effect of which store the approver happens to be working in.
     */
    public function execute(Procurement $procurement, Store|int|null $store = null): void
    {
        if (! $procurement->isPending()) {
            throw new RuntimeException('Only pending procurements can be approved.');
        }

        DB::transaction(function () use ($procurement, $store) {
            $approverId  = auth()->id();
            $destination = $procurement->store_id ?? $store;

            // Items that cannot be received here, collected rather than thrown
            // on sight: approving is a single decision, so the approver should
            // see everything wrong with the order at once instead of fixing it
            // one item per attempt.
            $blocked       = [];
            $destinationId = null;

            foreach ($procurement->items()->with('product')->get() as $item) {
                // Locked for the same reason the inventory actions lock it: it
                // serialises every writer of this product, which is what lets
                // the stock mirror be recomputed without racing.
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();

                $row       = StoreStock::lockedRow($product, $destination);
                $rehomedTo = null;

                // Every item resolves to the same branch, so the first one
                // settles where this order is landing — including when the
                // order predates the column and StoreStock fell back to the
                // vendor's default store.
                $destinationId ??= $row->store_id;

                // A product lives in exactly one branch — the till and the
                // inventory screens both read it that way. So receiving into a
                // branch that is not the product's home would put stock where
                // neither branch can see it: absent from this one because the
                // product is not homed here, and absent from its home branch
                // because the units are not in that row.
                if ((int) $product->store_id !== (int) $row->store_id) {
                    $heldElsewhere = ProductStoreStock::where('product_id', $product->id)
                        ->where('store_id', '!=', $row->store_id)
                        ->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('reserved', '>', 0))
                        ->exists();

                    // Holding nothing anywhere else, the product has no branch
                    // it is meaningfully tied to, so the order's destination
                    // becomes its home. This is what lets a newly opened
                    // branch be stocked by procurement at all.
                    if ($heldElsewhere) {
                        $blocked[] = $product->name;
                        continue;
                    }

                    $rehomedTo = $row->store_id;
                }

                $row->quantity += $item->quantity;
                $row->save();

                // The batch, at what this order actually paid for it — not at
                // the product's cost_price, which the update below is about to
                // overwrite with this same figure anyway. Recording the layer
                // here is what stops the next, dearer delivery revaluing these
                // units along with its own.
                StockCostLayers::receive(
                    productId: $product->id,
                    storeId: $row->store_id,
                    quantity: $item->quantity,
                    unitCost: $item->unit_cost !== null ? (float) $item->unit_cost : null,
                    source: $item,
                );

                $changes = [
                    'cost_price' => $item->unit_cost,
                    'price'      => $item->selling_price,
                ];

                if ($rehomedTo !== null) {
                    $changes['store_id'] = $rehomedTo;
                }

                $product->update($changes);

                InventoryLedger::create([
                    'vendor_id'        => $procurement->vendor_id,
                    'store_id'         => $row->store_id,
                    'product_id'       => $item->product_id,
                    'user_id'          => $approverId,
                    'transaction_type' => 'restock',
                    'quantity_change'  => $item->quantity,
                    'reference'        => $procurement->reference,
                    'description'      => "Procurement approved: {$procurement->reference}",
                ]);
            }

            if ($blocked !== []) {
                // Thrown inside the transaction, so nothing above it lands:
                // an order is received whole or not at all.
                $branch = Store::find($destinationId)?->name ?? 'this branch';

                throw new RuntimeException(sprintf(
                    '%s cannot be received into %s: %s already stocked at another branch. Create a separate product for this branch, or move that stock first.',
                    $procurement->reference,
                    $branch,
                    implode(', ', $blocked),
                ));
            }

            $procurement->update([
                'status'      => 'approved',
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);
        });
    }
}
