<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\FinancialAccount;
use App\Models\PosCustomer;
use App\Models\PosDebtPayment;
use App\Models\User;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A cashier collects money against a customer's running debt, from any
 * branch, during an open till session.
 *
 * Deliberately not routed through CashUpRectification/RecordRectificationAction:
 * that table's debt_paid kind flags one specific sale as "was actually paid" —
 * a manager's post-close correction, gated to only run once the cashier has
 * already submitted their counts. This is the opposite case — a live
 * collection against a running balance, not tied to one sale, happening
 * mid-shift — so it gets its own record (PosDebtPayment) and its own line in
 * CashUpExpectation, the same way Expense does for money leaving the drawer.
 *
 * The actual ledger/cash movement is unchanged from the existing admin-side
 * flow: RecordCustomerPaymentAction already does exactly "customer owes less,
 * business holds more" atomically, so it is called as-is here.
 */
class RecordDebtRepaymentAction
{
    public function __construct(
        private readonly RecordCustomerPaymentAction $recordPayment,
        private readonly CustomerDebtService $debts,
    ) {}

    public function execute(
        PosCustomer $customer,
        float $amount,
        string $method,
        User $collectedBy,
        ?int $storeId = null,
        ?string $note = null,
    ): PosDebtPayment {
        if (! in_array($method, PosDebtPayment::METHODS, true)) {
            throw new RuntimeException('Payment method must be cash, card, or bank transfer.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('A repayment has to be more than nothing.');
        }

        $outstanding = $this->debts->outstanding($customer->id);

        // Collecting more than is owed would turn a repayment into a credit
        // balance the customer never asked for and the shop never agreed to.
        if ($amount - $outstanding > 0.009) {
            throw new RuntimeException(sprintf(
                'This customer owes %s, so %s cannot be collected.',
                number_format($outstanding, 2),
                number_format($amount, 2),
            ));
        }

        $account = FinancialAccount::query()
            ->where('vendor_id', $customer->vendor_id)
            ->where('type', $method === 'cash' ? 'cash' : 'bank')
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new RuntimeException(
                $method === 'cash'
                    ? 'This shop has no cash account set up, so a repayment cannot be recorded. Ask your manager.'
                    : 'This shop has no bank account set up, so a repayment cannot be recorded. Ask your manager.'
            );
        }

        return DB::transaction(function () use ($customer, $amount, $method, $collectedBy, $storeId, $note, $account) {
            $ledgerEntry = $this->recordPayment->execute(
                customer: $customer,
                amount: $amount,
                collectedBy: $collectedBy,
                storeId: $storeId,
                note: $note,
                accountId: $account->id,
            );

            return PosDebtPayment::create([
                'pos_customer_id'              => $customer->id,
                'vendor_id'                    => $customer->vendor_id,
                'store_id'                     => $storeId,
                'collected_by'                 => $collectedBy->id,
                'pos_customer_ledger_entry_id' => $ledgerEntry->id,
                'method'                       => $method,
                'amount'                       => round($amount, 2),
                'note'                         => $note,
                'collected_at'                 => now()->toDateString(),
            ]);
        });
    }
}
