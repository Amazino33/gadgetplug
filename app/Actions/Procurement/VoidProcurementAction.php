<?php

namespace App\Actions\Procurement;

use App\Models\InventoryLedger;
use App\Models\Procurement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Voids a procurement that hasn't been reconciled yet — reconciled ones are
// a one-way door (see CorrectProcurementLogisticsAction for fixing those).
//
// draft and legacy pending never touched stock or pricing, so voiding them
// is a plain status flip. awaiting_logistics already took stock live and
// set provisional cost/price via SubmitProcurementForLogisticsAction, so
// voiding it must reverse the stock movement. Pricing (cost_price/price) is
// deliberately left as-is — another procurement or a manual edit may have
// already moved it on since submission, and guessing what to revert it to
// is riskier than leaving it for the vendor to re-price if needed.
class VoidProcurementAction
{
    private const VOIDABLE_STATUSES = ['draft', 'pending', 'awaiting_logistics'];

    public function execute(Procurement $procurement, string $reason): void
    {
        if (! in_array($procurement->status, self::VOIDABLE_STATUSES, true)) {
            throw new RuntimeException('Only a draft, pending, or awaiting-logistics procurement can be voided.');
        }

        DB::transaction(function () use ($procurement, $reason) {
            if ($procurement->status === 'awaiting_logistics') {
                $this->reverseStock($procurement);
            }

            $procurement->update(['status' => 'voided', 'void_reason' => $reason]);
        });
    }

    private function reverseStock(Procurement $procurement): void
    {
        $userId = auth()->id();

        foreach ($procurement->items()->with('product')->get() as $item) {
            $product = $item->product;
            $reversal = min($item->quantity, $product->stock_quantity);

            $product->decrement('stock_quantity', $reversal);

            InventoryLedger::create([
                'vendor_id' => $procurement->vendor_id,
                'product_id' => $item->product_id,
                'user_id' => $userId,
                'transaction_type' => 'audit_correction',
                'quantity_change' => -$reversal,
                'reference' => $procurement->reference,
                'description' => "Procurement voided — stock reversed: {$procurement->reference}",
            ]);
        }
    }
}
