<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;
use App\Policies\PosCustomerDebtPolicy;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closes a customer's balance by deciding it will not be collected.
 *
 * Nothing is posted to the financial ledger, and that is the point rather than
 * an omission. A credit sale never recognised revenue — the goods left, the
 * cost booked at stock-out, and the money was only ever going to appear when it
 * arrived. It never arrived. The loss is therefore already sitting in the
 * books as cost with no matching income, and posting anything here would count
 * the same loss twice.
 *
 * The ledger row is append-only like every other: forgiving a debt is recorded,
 * never erased, so the history still shows what was sold and what happened to it.
 */
class WriteOffCustomerDebtAction
{
    public function __construct(
        private CustomerDebtService $debt,
        private PosCustomerDebtPolicy $policy,
    ) {}

    public function execute(PosCustomer $customer, User $decidedBy, string $reason, ?int $storeId = null): PosCustomerLedgerEntry
    {
        // Authorisation is re-checked here, not left to the UI that called it.
        // A button that is merely hidden is not a rule.
        if (! $this->policy->writeOff($decidedBy, $customer)) {
            throw new RuntimeException('You are not allowed to write off this debt.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A write-off has to say why.');
        }

        return DB::transaction(function () use ($customer, $decidedBy, $reason, $storeId) {
            // Re-read inside the transaction: between opening the modal and
            // confirming it, a repayment may have landed and the amount to
            // forgive is smaller than the screen said.
            $outstanding = $this->debt->outstanding($customer->id);

            if ($outstanding <= 0) {
                throw new RuntimeException('There is nothing left to write off.');
            }

            return PosCustomerLedgerEntry::create([
                'pos_customer_id' => $customer->id,
                'vendor_id'       => $customer->vendor_id,
                'direction'       => PosCustomerLedgerEntry::DIRECTION_WRITEOFF,
                // Negative, like a payment: the sign convention is what keeps
                // outstanding a plain SUM.
                'amount'          => -1 * $outstanding,
                'store_id'        => $storeId,
                'created_by'      => $decidedBy->id,
                'occurred_at'     => now()->toDateString(),
                'description'     => 'Written off — ' . trim($reason),
            ]);
        });
    }
}
