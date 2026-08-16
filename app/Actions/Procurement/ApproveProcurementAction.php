<?php

namespace App\Actions\Procurement;

use App\Models\InventoryLedger;
use App\Models\Procurement;
use App\Models\Product;
use App\Services\Inventory\StoreStock;
use Illuminate\Support\Facades\DB;

class ApproveProcurementAction
{
    public function execute(Procurement $procurement): void
    {
        if (! $procurement->isPending()) {
            throw new \RuntimeException('Only pending procurements can be approved.');
        }

        DB::transaction(function () use ($procurement) {
            $approverId = auth()->id();

            foreach ($procurement->items()->with('product')->get() as $item) {
                // Locked for the same reason the inventory actions lock it: it
                // serialises every writer of this product, which is what lets
                // the stock mirror be recomputed without racing. This action
                // previously incremented without any lock at all.
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();

                // Increase stock, on the receiving store's row rather than the
                // product column. Procurement has no store of its own yet, so
                // this resolves to the vendor's default store — where this
                // stock has always implicitly landed.
                $row = StoreStock::lockedRow($product);
                $row->quantity += $item->quantity;
                $row->save();

                // Update cost price and selling price
                $product->update([
                    'cost_price' => $item->unit_cost,
                    'price'      => $item->selling_price,
                ]);

                // Ledger entry
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

            $procurement->update([
                'status'      => 'approved',
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);
        });
    }
}
