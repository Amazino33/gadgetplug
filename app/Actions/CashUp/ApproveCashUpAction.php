<?php

declare(strict_types=1);

namespace App\Actions\CashUp;

use App\Models\AccountabilityLedgerEntry;
use App\Models\PosSession;
use App\Models\User;
use App\Services\AccountabilityLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A manager signs off a cashier's day and the remaining difference becomes real.
 *
 * Posted at approval rather than at close, and that is a consequence of only
 * managers being allowed to rectify: at close the gap is still unexplained by
 * definition, because nobody has had the chance to explain it. Posting then
 * would charge a cashier the whole ₦5,000 and need a reversing entry the moment
 * the manager recorded the ₦3,000 of transport that accounts for most of it.
 * Approval is the first moment the figure is final, so it is the only honest
 * moment to write it down.
 *
 * What posts is the RESOLVED variance — what is still missing after everything
 * the manager accounted for. The frozen variance stays on the session as the
 * record of what was first put to the cashier; the two are different facts and
 * both are kept.
 *
 * Idempotent on the session. Approving twice posts one ledger row, because the
 * natural key is the session and the ledger refuses a second write against it.
 */
class ApproveCashUpAction
{
    public function __construct(private readonly AccountabilityLedger $ledger) {}

    public function execute(PosSession $session, User $reviewer, ?string $notes = null): PosSession
    {
        if ($session->isApproved()) {
            // Not an error. A double-clicked button should leave the day
            // approved, not report a failure at something already true.
            return $session;
        }

        if (! $session->isPendingReview()) {
            throw new RuntimeException('This cash-up has not been submitted yet, so there is nothing to approve.');
        }

        // Defence in depth. The policy gate in the panel says the same thing, and
        // this says it again where no UI can route around it: whoever counted the
        // money does not get to be the one who accepts the count.
        if ((int) $session->cashier_id === $reviewer->id) {
            throw new RuntimeException('You cannot approve your own cash-up.');
        }

        return DB::transaction(function () use ($session, $reviewer, $notes) {
            // Read inside the transaction: a rectification landing between the
            // read and the write would otherwise be left out of the figure the
            // cashier is charged.
            $session->load('rectifications');

            $this->ledger->postCashVariance(
                vendorId: (int) $session->vendor_id,
                cashierId: (int) $session->cashier_id,
                variance: $session->resolvedCashVariance(),
                naturalKey: self::naturalKeyFor($session),
                storeId: (int) $session->store_id,
                sourceType: PosSession::class,
                sourceId: (int) $session->id,
                createdBy: $reviewer->id,
                note: $this->note($session),
            );

            $session->update([
                'status'      => PosSession::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'notes'       => $notes ?? $session->notes,
            ]);

            activity()->causedBy($reviewer)
                ->performedOn($session)
                ->withProperties([
                    'cash_variance_at_close' => (float) $session->cash_variance,
                    'cash_variance_resolved' => $session->resolvedCashVariance(),
                    'terminal_variance_resolved' => $session->resolvedTerminalVariance(),
                ])
                ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
                ->log('Approved cash-up');

            return $session->refresh();
        });
    }

    /** One posting per cash-up, forever. */
    public static function naturalKeyFor(PosSession $session): string
    {
        return "cashup:{$session->id}:cash";
    }

    /** The ledger row already posted for this cash-up, if any. */
    public static function postingFor(PosSession $session): ?AccountabilityLedgerEntry
    {
        return AccountabilityLedgerEntry::where('idempotency_key', self::naturalKeyFor($session))->first();
    }

    private function note(PosSession $session): string
    {
        return sprintf(
            'Cash-up %s — drawer counted %s against %s expected',
            $session->business_date->toDateString(),
            number_format((float) $session->counted_cash, 2),
            number_format((float) $session->expected_cash, 2),
        );
    }
}
