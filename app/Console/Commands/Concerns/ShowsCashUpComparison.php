<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\PosSession;
use App\Support\Pos\CashUpBreakdown;

/** The before-and-after table every cash-up repair shows before it writes. */
trait ShowsCashUpComparison
{
    protected function showComparison(PosSession $session, CashUpBreakdown $after, string $beforeLabel, string $afterLabel): void
    {
        $counted = (float) $session->counted_cash;
        $countedTerminal = (float) $session->counted_terminal;
        $money = fn ($v) => number_format((float) $v, 2);

        $this->table(['', $beforeLabel, $afterLabel], [
            ['Sales counted in', $session->breakdown['context']['sales_count'] ?? '?', $after->context['sales_count'] ?? 0],
            ['Counted cash', $money($counted), $money($counted)],
            ['Expected cash', $money($session->expected_cash), $money($after->expectedCash)],
            ['Cash difference', $money($session->cash_variance), $money($after->cashVarianceAgainst($counted))],
            ['Counted terminal', $money($countedTerminal), $money($countedTerminal)],
            ['Expected terminal', $money($session->expected_terminal), $money($after->expectedTerminal)],
            ['Terminal difference', $money($session->terminal_variance), $money($after->terminalVarianceAgainst($countedTerminal))],
        ]);
    }
}
