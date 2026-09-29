<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ShowsCashUpComparison;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Services\Cash\CashUpRefreeze;
use App\Support\Pos\BusinessDate;
use Illuminate\Console\Command;

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
    use ShowsCashUpComparison;

    protected $signature = 'pos:redate-cash-up
                            {session : The End of Day record (pos_sessions) id}
                            {date : The trading day it actually belongs to, as YYYY-MM-DD}
                            {--force : Actually move it. Without this, only reports the plan.}';

    protected $description = 'Move a cash-up filed under the wrong trading day back to its own day, and recompute what it should have held';

    public function handle(CashUpRefreeze $refreeze): int
    {
        $session = PosSession::with('cashier', 'store')->find($this->argument('session'));

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

        if ($refusal = $refreeze->refusal($session)) {
            $this->error($refusal);

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

        $breakdown = $refreeze->preview($session, $date);

        $this->showComparison($session, $breakdown, "Now ({$from})", "Moved ({$date})");

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to move it.');

            return self::SUCCESS;
        }

        $refreeze->apply($session, $date, $breakdown, 'Moved cash-up to its own trading day');

        $this->info("Moved to {$date}. The cashier's {$from} can now be cashed up.");

        return self::SUCCESS;
    }
}
