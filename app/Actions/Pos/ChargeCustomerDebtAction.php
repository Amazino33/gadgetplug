<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use Illuminate\Support\Facades\Log;

/**
 * Puts the unpaid slice of a till sale onto the customer's ledger.
 *
 * Shared by the online path and the offline sync path deliberately: a sale rung
 * up with no signal must produce exactly the same charge when it finally
 * uploads as it would have at the counter. Two copies of this rule would drift.
 *
 * The debt amount is read from the sale's tender rows, never from its total.
 * That is the whole reason a debt sale writes per-tender rows even when debt is
 * the only tender — the total says what the goods cost, and only the tenders say
 * how much of it walked out unpaid.
 */
class ChargeCustomerDebtAction
{
    public function execute(PosSale $sale): ?PosCustomerLedgerEntry
    {
        $amount = $this->debtAmount($sale);

        if ($amount <= 0) {
            return null;
        }

        // No customer, no debt. The till refuses this before it gets here, so
        // reaching it means a sync payload was hand-built or tampered with —
        // and an anonymous debt is worse than a rejected sale, because nobody
        // can ever be asked to pay it.
        if (! $sale->customer_id) {
            // Logged rather than thrown: this runs mid-sync over a batch of
            // already-completed sales, and refusing the batch would strand
            // every other sale in it. The sale is still recorded — what is lost
            // is the receivable, which is exactly why it must not pass quietly.
            Log::warning("Credit sale {$sale->reference} has no customer, so no debt was recorded for it.");

            return null;
        }

        // One charge per sale. Sourced from the sale itself, so a retried sync
        // of the same reference finds the charge already posted rather than
        // doubling what the customer owes.
        $existing = PosCustomerLedgerEntry::where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->getKey())
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->first();

        if ($existing) {
            return $existing;
        }

        return PosCustomerLedgerEntry::create([
            'pos_customer_id' => $sale->customer_id,
            'vendor_id'       => $sale->vendor_id,
            'direction'       => PosCustomerLedgerEntry::DIRECTION_CHARGE,
            'amount'          => $amount,
            'source_type'     => $sale->getMorphClass(),
            'source_id'       => $sale->getKey(),
            // The branch that handed over the goods. Where it gets repaid is a
            // separate fact, recorded on its own row.
            'store_id'        => $sale->store_id,
            'created_by'      => $sale->cashier_id,
            // The business date, which for an offline sale is when it was rung
            // up rather than when it happened to reach the server.
            'occurred_at'     => ($sale->completed_at ?? $sale->created_at)->toDateString(),
            'description'     => "Credit sale — {$sale->reference}",
        ]);
    }

    /** The portion of this sale tendered as debt. */
    public function debtAmount(PosSale $sale): float
    {
        return round((float) PosSalePayment::where('pos_sale_id', $sale->id)
            ->where('method', 'debt')
            ->sum('amount'), 2);
    }
}
