<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\PosSession;
use App\Support\Pos\CashUpBreakdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Works a counted day's expected figures out again, and writes them over the
 * ones frozen at close.
 *
 * The frozen figures are evidence and the model refuses to let them move. The
 * two repairs that may move them anyway — a record filed under the wrong day,
 * and a record whose sales reached the server after it was counted — both
 * come through here, so they refuse the same things and leave the same trail.
 *
 * The counts are never touched. They are the cashier's word; only what the
 * server held them to is being corrected.
 */
class CashUpRefreeze
{
    public function __construct(
        private CashUpExpectation $expectation,
        private ZReportWriter $zReports,
    ) {}

    /** Why this record may not be reworked, or null if it may. */
    public function refusal(PosSession $session): ?string
    {
        if (! $session->isPendingReview()) {
            return "Only a record waiting for review can be reworked. This one is '{$session->status}'.";
        }

        if ($session->rectifications()->exists()) {
            return 'A manager has already explained part of this record against its current figures. Remove those explanations first.';
        }

        return null;
    }

    /**
     * What the record should have held on the given day.
     *
     * Without rectifications, exactly as the close computes it: none can exist
     * at close, and refusal() makes sure none exist here either.
     */
    public function preview(PosSession $session, string $businessDate): CashUpBreakdown
    {
        return $this->expectation->compute(
            vendorId: (int) $session->vendor_id,
            storeId: (int) $session->store_id,
            cashierId: (int) $session->cashier_id,
            businessDate: $businessDate,
            openingFloat: (float) $session->opening_float,
        );
    }

    public function apply(PosSession $session, string $businessDate, CashUpBreakdown $breakdown, string $why): void
    {
        $before = $session->only(['business_date', 'expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance', 'breakdown']);

        DB::transaction(function () use ($session, $businessDate, $breakdown) {
            // Written past the model, deliberately: the frozen-evidence guard
            // exists to stop a count being quietly re-measured, and this is the
            // one sanctioned, logged exception.
            PosSession::whereKey($session->id)->update([
                // Bound as a date rather than a bare string, so it is stored in
                // the same shape the model writes and the unique key still bites.
                'business_date'     => Carbon::parse($businessDate)->startOfDay(),
                'expected_cash'     => $breakdown->expectedCash,
                'expected_terminal' => $breakdown->expectedTerminal,
                'cash_variance'     => $breakdown->cashVarianceAgainst((float) $session->counted_cash),
                'terminal_variance' => $breakdown->terminalVarianceAgainst((float) $session->counted_terminal),
                'breakdown'         => json_encode($breakdown->toArray()),
            ]);

            $this->zReports->write($session->refresh());
        });

        // The whole of what was there before, breakdown included, so any
        // rework can be put back exactly.
        activity()
            ->performedOn($session)
            ->withProperties(['before' => $before, 'business_date' => $businessDate])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log($why);
    }
}
