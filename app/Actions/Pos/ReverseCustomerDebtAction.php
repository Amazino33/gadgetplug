<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\PosCustomerLedgerEntry;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Services\Pos\CustomerDebtService;

/**
 * Takes back what a customer owes when the goods come back.
 *
 * A credit sale puts a charge on the customer's ledger. If that sale is voided,
 * or the goods are returned, the customer is still shown as owing for something
 * they no longer have — and a storekeeper's debt list will tell them to go and
 * collect it. That is the one wrong number this whole system must never produce.
 *
 * Written as an opposing row rather than by touching the original charge,
 * because the ledger is append-only: the history has to keep saying that credit
 * was extended and then reversed, not quietly pretend it never happened.
 */
class ReverseCustomerDebtAction
{
    public function __construct(private CustomerDebtService $debt) {}

    /**
     * Reverses the whole charge — the sale is gone entirely.
     */
    public function forVoid(PosSale $sale): ?PosCustomerLedgerEntry
    {
        return $this->reverse(
            $sale,
            $this->chargedFor($sale),
            "Credit sale voided — {$sale->reference}",
            'void',
        );
    }

    /**
     * Reverses the part of the charge the returned goods represent.
     *
     * Capped at what is actually still owed: if the customer has already paid
     * some of it back, returning the goods cannot put their balance below zero
     * and turn a debt into a refund the store never agreed to. Cash owed back
     * to a customer is a different conversation from a debt being cancelled,
     * and it is not this action's job to start it.
     */
    public function forReturn(PosSale $sale, PosReturn $posReturn): ?PosCustomerLedgerEntry
    {
        $charged = $this->chargedFor($sale);

        if ($charged <= 0) {
            return null;
        }

        // A part-paid credit sale: only the unpaid share of the returned value
        // is debt to cancel. The rest was real money and belongs in the refund
        // the return itself is already handling.
        $unpaidShare = min(1.0, $charged / max((float) $sale->total, 0.01));
        $amount      = round((float) $posReturn->refund_amount * $unpaidShare, 2);

        return $this->reverse(
            $sale,
            $amount,
            "Goods returned — {$posReturn->reference} against {$sale->reference}",
            'return:' . $posReturn->id,
        );
    }

    /** What this sale originally put on the customer's ledger. */
    private function chargedFor(PosSale $sale): float
    {
        return round((float) PosCustomerLedgerEntry::where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->getKey())
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->sum('amount'), 2);
    }

    private function reverse(PosSale $sale, float $amount, string $description, string $key): ?PosCustomerLedgerEntry
    {
        if ($amount <= 0 || ! $sale->customer_id) {
            return null;
        }

        // Never more than the customer still owes. Repayments may already have
        // cleared part or all of it, and reversing beyond that would leave them
        // in credit off the back of goods they were never charged for.
        $amount = min($amount, max($this->debt->outstanding($sale->customer_id), 0));

        if ($amount <= 0) {
            return null;
        }

        // Idempotent per reversal: a retried void, or the same return processed
        // twice, finds its own row already written rather than cancelling the
        // debt twice over.
        $existing = PosCustomerLedgerEntry::where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->getKey())
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_WRITEOFF)
            ->where('description', 'like', "%[{$key}]")
            ->first();

        if ($existing) {
            return $existing;
        }

        return PosCustomerLedgerEntry::create([
            'pos_customer_id' => $sale->customer_id,
            'vendor_id'       => $sale->vendor_id,
            // Recorded as a write-off, which is what it is in ledger terms: the
            // customer will not be paying it. It reads differently from a
            // forgiven debt only in its description, and deliberately does not
            // go through the owner-only write-off action — nobody is choosing
            // to forgive anything here, the goods simply came back.
            'direction'       => PosCustomerLedgerEntry::DIRECTION_WRITEOFF,
            'amount'          => -1 * $amount,
            'store_id'        => $sale->store_id,
            'created_by'      => auth()->id(),
            'occurred_at'     => now()->toDateString(),
            'description'     => "{$description} [{$key}]",
        ]);
    }
}
