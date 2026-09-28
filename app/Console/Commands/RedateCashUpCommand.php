<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PosSale;
use App\Models\PosSession;
use App\Services\Cash\CashUpExpectation;
use App\Services\Cash\ZReportWriter;
use App\Support\Pos\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a cash-up that was filed under the wrong trading day back to its own.
 *
 * Until the fix alongside this command, a till whose shift could not reach the
 * server on its own day sent it the next day, and the server stamped it with
 * the day it arrived. Its counts were then frozen against a day that had barely
 * begun — nothing expected, everything "over" — and the cashier's real day was
 * locked out as "already cashed up".
 *
 * The counts are the cashier's word and are left exactly as they are. Only the
 * date moves, and the expected figures are worked out again for that date,
 * because the ones frozen at close were measured against the wrong day and so
 * prove nothing about the drawer.
 *
 * Refuses anything a person has already acted on: an approved day has posted
 * to the ledger, and rectifications were written against the wrong figures.
 */
class RedateCashUpCommand extends Command
{
    protected $signature = 'pos:redate-cash-up
                            {session : The End of Day record (pos_sessions) id}
                            {date : The trading day it actually belongs to, as YYYY-MM-DD}
                            {--force : Actually move it. Without this, only reports the plan.}';

    protected $description = 'Move a cash-up filed under the wrong trading day back to its own day, and recompute what it should have held';

    public function handle(CashUpExpectation $expectation, ZReportWriter $zReports): int
    {
        $session = PosSession::with('cashier', 'store', 'rectifications')->find($this->argument('session'));

        if (! $session) {
            $this->error('No End of Day record with that id.');

            return self::FAILURE;
        }

        $date = (string) $this->argument('date');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $this->error('The date must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $from = $session->business_date->toDateString();

        $this->info("Record #{$session->id}: {$session->cashier?->name} at {$session->store?->name}, filed under {$from}");

        if ($date === $from) {
            $this->info('It is already filed under that day. Nothing to do.');

            return self::SUCCESS;
        }

        if ($date > BusinessDate::today()) {
            $this->error('That day has not happened yet.');

            return self::FAILURE;
        }

        if (! $session->isPendingReview()) {
            $this->error("Only a record waiting for review can be moved. This one is '{$session->status}'.");

            return self::FAILURE;
        }

        if ($session->rectifications->isNotEmpty()) {
            $this->error('A manager has already explained part of this record against its current figures. Remove those explanations first.');

            return self::FAILURE;
        }

        $clash = PosSession::forDay((int) $session->cashier_id, (int) $session->store_id, $date);

        if ($clash) {
            $this->error("This cashier already has record #{$clash->id} for {$date} at this branch.");

            return self::FAILURE;
        }

        // A count belongs to a day the cashier actually traded. Without this the
        // command would happily move a record onto an empty day — which on
        // 28/09/2026 it did, to a Sunday the shop was shut, on a guess.
        [$dayStart, $dayEnd] = BusinessDate::boundsFor($date);

        $salesThatDay = PosSale::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('store_id', $session->store_id)
            ->where('cashier_id', $session->cashier_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$dayStart, $dayEnd])
            ->count();

        if ($salesThatDay === 0) {
            $this->error("{$session->cashier?->name} rang no sales at this branch on {$date}. Nothing shows the count belongs to that day, so it has not been moved.");

            return self::FAILURE;
        }

        $breakdown = $expectation->compute(
            vendorId: (int) $session->vendor_id,
            storeId: (int) $session->store_id,
            cashierId: (int) $session->cashier_id,
            businessDate: $date,
            openingFloat: (float) $session->opening_float,
        );

        $countedCash = (float) $session->counted_cash;
        $countedTerminal = (float) $session->counted_terminal;

        $this->table(['', "Now ({$from})", "Moved ({$date})"], [
            ['Counted cash', $countedCash, $countedCash],
            ['Expected cash', (float) $session->expected_cash, $breakdown->expectedCash],
            ['Cash difference', (float) $session->cash_variance, $breakdown->cashVarianceAgainst($countedCash)],
            ['Counted terminal', $countedTerminal, $countedTerminal],
            ['Expected terminal', (float) $session->expected_terminal, $breakdown->expectedTerminal],
            ['Terminal difference', (float) $session->terminal_variance, $breakdown->terminalVarianceAgainst($countedTerminal)],
        ]);

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to move it.');

            return self::SUCCESS;
        }

        $before = $session->only(['business_date', 'expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance']);

        DB::transaction(function () use ($session, $date, $breakdown, $countedCash, $countedTerminal, $zReports) {
            // Written past the model, deliberately: the frozen-evidence guard
            // exists to stop a count being quietly re-measured, and this is the
            // one sanctioned, logged exception — the old figures were measured
            // against the wrong day and never described this drawer.
            PosSession::whereKey($session->id)->update([
                // Bound as a date rather than a bare string, so it is stored in
                // the same shape the model writes and the unique key still bites.
                'business_date'     => Carbon::parse($date)->startOfDay(),
                'expected_cash'     => $breakdown->expectedCash,
                'expected_terminal' => $breakdown->expectedTerminal,
                'cash_variance'     => $breakdown->cashVarianceAgainst($countedCash),
                'terminal_variance' => $breakdown->terminalVarianceAgainst($countedTerminal),
                'breakdown'         => json_encode($breakdown->toArray()),
            ]);

            $zReports->write($session->refresh());
        });

        activity()
            ->performedOn($session)
            ->withProperties(['before' => $before, 'moved_to' => $date])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Moved cash-up to its own trading day');

        $this->info("Moved to {$date}. The cashier's {$from} can now be cashed up.");

        return self::SUCCESS;
    }
}
