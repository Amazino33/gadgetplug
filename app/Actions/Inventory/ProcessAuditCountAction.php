<?php

namespace App\Actions\Inventory;

use App\Models\AuditSession;
use Illuminate\Support\Facades\DB;
use Exception;

class ProcessAuditCountAction
{
    // We inject our current notebook writer so we can re-use it!
    public function __construct(protected AdjustStockAction $adjustStock) {}

    /**
     * @throws Exception
     */
    public function execute(AuditSession $audit, int $storeKeeperBId, int $countB)
    {
        return DB::transaction(function () use ($audit, $storeKeeperBId, $countB) {
            // 1. Lock the session so two people can't verify it at the exact same millisecond
            $audit = AuditSession::where('id', $audit->id)->lockForUpdate()->firstOrFail();

            // 2. Validate the rules
            if ($audit->status !== 'pending') {
                throw new Exception("This audit session has already been processed.");
            }

            if ($audit->storekeeper_a_id === $storeKeeperBId) {
                throw new Exception("Security Alert: You cannot verify your own count.");
            }

            // 3. Record Storekeeper B's count
            $audit->storekeeper_b_id = $storeKeeperBId;
            $audit->count_b = $countB;

            // 4. The match logic
            if ($audit->count_a === $audit->count_b) {
                $audit->status = 'verified';

                // We need to figure out how much stock is "missing" or "extra"
                $currentSystemStock = $audit->product->stock_quantity;
                $quantityDifference = $audit->count_b - $currentSystemStock;

                // Only write to the ledger if the stock actually changed
                if ($quantityDifference !== 0) {
                    $this->adjustStock->execute(
                        productId: $audit->product_id,
                        quantityChanged: $quantityDifference,
                        transactionType: 'audit_correction',
                        userId: $storeKeeperBId,
                        reference: "Audit #{$audit->id}",
                        description: "Inventory count verified. System expected {$currentSystemStock}, actually found {$audit->count_b}."
                    );
                }
            } else {
                // 5. The discrepancy logic
                $audit->status = 'discrepancy';
                // Note: We don't touch the stock here. The manager will review and decide if an override is needed.
            }

            $audit->save();

            return $audit;
        });
    }
}