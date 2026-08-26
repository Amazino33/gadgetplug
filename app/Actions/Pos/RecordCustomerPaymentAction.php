<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\FinancialAccount;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;
use App\Services\FinancialLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records money received against a customer's debt.
 *
 * A repayment is two movements that must be true together: the customer owes
 * less, and the business holds more cash. They are written in one transaction
 * because either one alone is a lie — a payment row with no cash says money
 * arrived and vanished, a cash row with no payment says the customer still owes
 * what they just settled.
 *
 * The cash entry is sourced from the ledger row, never from the customer or the
 * debt as a whole. FinancialLedger keys idempotency on
 * (source_type, source_id, direction) and returns the existing row rather than
 * posting again — so a second repayment sourced from anything shared would be
 * silently swallowed and the money would never reach the accounts. Each payment
 * row is unique, so each repayment posts.
 */
class RecordCustomerPaymentAction
{
    public function execute(
        PosCustomer $customer,
        float $amount,
        User $collectedBy,
        ?int $storeId = null,
        ?string $note = null,
    ): PosCustomerLedgerEntry {
        if ($amount <= 0) {
            throw new RuntimeException('A repayment has to be more than nothing.');
        }

        return DB::transaction(function () use ($customer, $amount, $collectedBy, $storeId, $note) {
            // Negative: the sign convention is what lets outstanding stay a
            // plain SUM, and the model enforces it on the way in.
            $payment = PosCustomerLedgerEntry::create([
                'pos_customer_id' => $customer->id,
                'vendor_id'       => $customer->vendor_id,
                'direction'       => PosCustomerLedgerEntry::DIRECTION_PAYMENT,
                'amount'          => -1 * round($amount, 2),
                'store_id'        => $storeId,
                'created_by'      => $collectedBy->id,
                'occurred_at'     => now()->toDateString(),
                'description'     => $note ?: 'Debt repayment',
            ]);

            $this->postCash($customer, $payment, round($amount, 2), $collectedBy, $storeId);

            return $payment;
        });
    }

    /**
     * The cash side. Recognised now rather than deferred: this is the money
     * arriving that the credit sale deliberately did not recognise at the till,
     * which is what makes the revenue cash-basis rather than merely late.
     */
    private function postCash(
        PosCustomer $customer,
        PosCustomerLedgerEntry $payment,
        float $amount,
        User $collectedBy,
        ?int $storeId,
    ): void {
        $account = FinancialAccount::where('vendor_id', $customer->vendor_id)
            ->where('type', 'cash')
            ->first();

        if (! $account) {
            // Thrown, not logged. A till sale swallows this because the customer
            // is standing there holding goods and the sale must not roll back —
            // here nothing has happened yet, so failing loudly is free, and
            // recording a repayment that never reached the accounts would leave
            // the books permanently short.
            throw new RuntimeException('No cash account exists for this store, so the payment cannot be recorded.');
        }

        FinancialLedger::postEntry(
            account: $account,
            direction: 'in',
            amount: $amount,
            source: $payment,
            description: "Debt repayment — {$customer->name}",
            createdBy: $collectedBy->id,
            storeId: $storeId,
        );
    }
}
