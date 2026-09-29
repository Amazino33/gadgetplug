<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ShowsCashUpComparison;
use App\Models\PosSession;
use App\Services\Cash\CashUpRefreeze;
use Illuminate\Console\Command;

/**
 * Re-measures a counted day against sales that reached the server after it
 * was counted.
 *
 * A till's queued sales could be held back — by a lost connection, or by the
 * till-login bug of 26/09/2026 — while its cash-up went through. The expected
 * figures were then frozen without them, and the cashier looks short by
 * exactly the sales the server had not yet seen.
 *
 * Same day, same counts; only the expected figures are worked out again.
 * Found by pos:audit-cash-ups, which lists every record this applies to.
 */
class RefreshCashUpCommand extends Command
{
    use ShowsCashUpComparison;

    protected $signature = 'pos:refresh-cash-up
                            {session : The End of Day record (pos_sessions) id}
                            {--force : Actually rework it. Without this, only reports the plan.}';

    protected $description = 'Recompute a cash-up whose sales reached the server after it was counted';

    public function handle(CashUpRefreeze $refreeze): int
    {
        $session = PosSession::with('cashier', 'store')->find($this->argument('session'));

        if (! $session) {
            $this->error('No End of Day record with that id.');

            return self::FAILURE;
        }

        $date = $session->business_date->toDateString();

        $this->info("Record #{$session->id}: {$session->cashier?->name} at {$session->store?->name}, {$date}");

        if ($refusal = $refreeze->refusal($session)) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $breakdown = $refreeze->preview($session, $date);

        if (abs($breakdown->expectedCash - (float) $session->expected_cash) < 0.01
            && abs($breakdown->expectedTerminal - (float) $session->expected_terminal) < 0.01) {
            $this->info('Its expected figures already include every sale for that day. Nothing to do.');

            return self::SUCCESS;
        }

        $this->showComparison($session, $breakdown, 'Frozen at close', 'With every sale now in');

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to rework it.');

            return self::SUCCESS;
        }

        $refreeze->apply($session, $date, $breakdown, 'Recomputed cash-up with sales that arrived after it was counted');

        $this->info('Reworked. The counts are unchanged; the expected figures now include every sale for the day.');

        return self::SUCCESS;
    }
}
