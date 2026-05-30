<?php

namespace App\Actions\Procurement;

use App\Models\InventoryLedger;
use App\Models\Procurement;
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
                $product = $item->product;

                // Increase stock
                $product->increment('stock_quantity', $item->quantity);

                // Update cost price and selling price
                $product->update([
                    'cost_price' => $item->unit_cost,
                    'price'      => $item->selling_price,
                ]);

                // Ledger entry
                InventoryLedger::create([
                    'vendor_id'        => $procurement->vendor_id,
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
