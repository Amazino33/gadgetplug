<?php

declare(strict_types=1);

namespace App\Actions\CashUp;

use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Models\CashUpRectification;
use App\Models\CashUpSession;
use App\Models\PosSale;
use App\Models\User;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A manager accounts for part of a difference.
 *
 * Only a manager. The cashier says what happened, in words, on close; somebody
 * else decides whether it explains the money. A system that lets the person a
 * shortage names write the shortage off is not a control, and this is the one
 * rule the whole feature rests on.
 *
 * Appends, never edits. The original sale keeps saying exactly what it was rung
 * as — that is what a till journal is for — and the correction sits beside it.
 * A rectification entered wrongly is answered with an opposing one.
 *
 * Posted immediately rather than held until approval, because two of these move
 * real money: a debt_paid means a customer who has actually paid stops being
 * chased, and making them wait for a manager's sign-off on an unrelated part of
 * the day would be chasing them for money the shop already agrees it has.
 */
class RecordRectificationAction
{
    public function __construct(
        private readonly RecordCustomerPaymentAction $recordPayment,
        private readonly CustomerDebtService $debts,
    ) {}

    public function execute(
        CashUpSession $session,
        User $manager,
        string $kind,
        float $amount,
        ?string $fromTender = null,
        ?string $toTender = null,
        ?int $relatedSaleId = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): CashUpRectification {
        if (! $session->acceptsRectifications()) {
            throw new RuntimeException($session->isApproved()
                ? 'This cash-up has been approved and can no longer be rectified. Post a correcting ledger entry instead.'
                : 'A cash-up can only be rectified once the cashier has submitted their counts.');
        }

        // The control the whole feature rests on.
        if ((int) $session->cashier_id === $manager->id) {
            throw new RuntimeException('You cannot explain away a difference on your own drawer.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('A rectification has to be for some money.');
        }

        $sale = $relatedSaleId === null ? null : $this->resolveSale($session, $relatedSaleId);

        return DB::transaction(function () use (
            $session, $manager, $kind, $amount, $fromTender, $toTender, $sale, $note, $idempotencyKey
        ) {
            // An idempotency key that has already been used returns its own row
            // rather than appending a second identical explanation.
            if (filled($idempotencyKey)) {
                $existing = CashUpRectification::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            $entry = CashUpRectification::create([
                'cash_up_session_id' => $session->id,
                'vendor_id'          => $session->vendor_id,
                'kind'               => $kind,
                'amount'             => round($amount, 2),
                'from_tender'        => $fromTender,
                'to_tender'          => $toTender,
                'related_sale_id'    => $sale?->id,
                'note'               => $note,
                'created_by'         => $manager->id,
                'idempotency_key'    => $idempotencyKey,
                'created_at'         => now(),
            ]);

            if ($entry->kind === CashUpRectification::KIND_DEBT_PAID) {
                $this->settleDebt($entry, $sale, $manager, $session);
            }

            activity()->causedBy($manager)
                ->performedOn($session)
                ->withProperties([
                    'kind'   => $entry->kind,
                    'amount' => (float) $entry->amount,
                    'sale'   => $sale?->reference,
                ])
                ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
                ->log('Rectified cash-up');

            return $entry;
        });
    }

    /**
     * The sale must be this cashier's, at this branch, or the correction is
     * being aimed at somebody else's day.
     */
    private function resolveSale(CashUpSession $session, int $saleId): PosSale
    {
        $sale = PosSale::find($saleId);

        if (! $sale
            || (int) $sale->vendor_id !== (int) $session->vendor_id
            || (int) $sale->cashier_id !== (int) $session->cashier_id
            || (int) $sale->store_id !== (int) $session->store_id) {
            throw new RuntimeException('That sale does not belong to this cash-up.');
        }

        return $sale;
    }

    /**
     * A credit sale that was actually paid stops being a debt.
     *
     * Routed through RecordCustomerPaymentAction rather than writing the ledger
     * row directly, because a repayment is two movements: the customer owes less
     * AND the business holds more. Writing only the first would clear the debt
     * with the money never reaching the accounts, and the books would stay
     * permanently short by the amount.
     */
    private function settleDebt(
        CashUpRectification $entry,
        ?PosSale $sale,
        User $manager,
        CashUpSession $session,
    ): void {
        if (! $sale?->customer_id) {
            throw new RuntimeException('That sale has no customer, so there is no debt on it to settle.');
        }

        $customer = $sale->customer;
        $outstanding = $this->debts->outstanding($customer->id);
        $amount = (float) $entry->amount;

        // Settling more than is owed would turn a correction into a credit
        // balance the customer never paid for.
        if ($amount - $outstanding > 0.009) {
            throw new RuntimeException(sprintf(
                'This customer owes %s, so %s cannot be settled against them.',
                number_format($outstanding, 2),
                number_format($amount, 2),
            ));
        }

        $this->recordPayment->execute(
            customer: $customer,
            amount: $amount,
            collectedBy: $manager,
            storeId: (int) $session->store_id,
            note: "Credit sale {$sale->reference} was paid at the till — corrected at cash-up",
        );
    }
}
