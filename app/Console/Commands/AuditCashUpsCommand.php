<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PosSale;
use App\Models\PosSession;
use App\Services\Cash\CashUpExpectation;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Looks across every branch for End of Day records the server got wrong.
 *
 * Read-only. It writes nothing and suggests the repair for each finding, so a
 * person decides — the lesson of 28/09/2026, when a record was moved on a guess.
 *
 * Three things go wrong when a till's day reaches the server late:
 *
 * - Misfiled: the cash-up arrived on a later day and was stamped with that day,
 *   so it was measured against a morning with no sales. Shows as real counts
 *   against nothing expected. The cashier's uncounted days nearby are tried
 *   against the counts, and one that balances is named.
 * - Out of date: sales reached the server after the cash-up, so the frozen
 *   expected figures are missing them.
 * - Uncounted: sales on a day with no record at all — often the day a
 *   misfiled record belongs to, or a count the old till dropped.
 */
class AuditCashUpsCommand extends Command
{
    protected $signature = 'pos:audit-cash-ups
                            {--since= : First trading day to check, YYYY-MM-DD (default: 14 days ago)}
                            {--vendor= : Only this vendor id}';

    protected $description = 'Read-only: find End of Day records filed under the wrong day, missing late sales, or missing altogether';

    /** How far back from a misfiled record to look for the day it belongs to. */
    private const LOOKBACK_DAYS = 14;

    public function handle(CashUpExpectation $expectation): int
    {
        $today = BusinessDate::today();
        $since = $this->option('since') ?: CarbonImmutable::parse($today)->subDays(self::LOOKBACK_DAYS)->toDateString();
        $vendorId = $this->option('vendor') ? (int) $this->option('vendor') : null;

        [$from] = BusinessDate::boundsFor($since);

        $this->info("Checking End of Day records from {$since} to {$today}. Nothing is changed.");

        $sessions = PosSession::query()
            ->with('cashier:id,name', 'store:id,name', 'vendor:id,name')
            ->whereNotNull('business_date')
            ->whereNotNull('store_id')
            ->whereDate('business_date', '>=', $since)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->orderBy('vendor_id')->orderBy('business_date')
            ->get();

        $sales = PosSale::query()
            ->with('cashier:id,name', 'store:id,name')
            ->where('status', '!=', 'voided')
            ->whereNotNull('store_id')
            ->where('completed_at', '>=', $from)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->get(['id', 'vendor_id', 'store_id', 'cashier_id', 'total', 'completed_at', 'created_at']);

        $key = fn ($vendor, $store, $cashier, $date) => "{$vendor}|{$store}|{$cashier}|{$date}";

        $salesByDay = $sales->groupBy(fn (PosSale $s) => $key($s->vendor_id, $s->store_id, $s->cashier_id, BusinessDate::of($s->completed_at)));

        $recorded = $sessions->mapWithKeys(fn (PosSession $s) => [
            $key($s->vendor_id, $s->store_id, $s->cashier_id, $s->business_date->toDateString()) => true,
        ]);

        $uncounted = $salesByDay->reject(fn ($group, $k) => isset($recorded[$k]));
        $claimed = [];
        $findings = 0;

        foreach ($sessions->filter(fn (PosSession $s) => $s->counted_cash !== null) as $session) {
            $date = $session->business_date->toDateString();
            $countedCash = (float) $session->counted_cash;
            $countedTerminal = (float) $session->counted_terminal;
            $frozenSales = $session->breakdown['context']['sales_count'] ?? null;

            $misfiled = ($countedCash + $countedTerminal) > 0.009
                && abs((float) $session->expected_cash - (float) $session->opening_float) < 0.01
                && abs((float) $session->expected_terminal) < 0.01
                && (int) $frozenSales === 0;

            if ($misfiled) {
                $findings++;
                $this->newLine();
                $this->warn('MISFILED? '.$this->describe($session));
                $this->line('  Real counts, measured against a day with no sales yet when it was counted.');

                $candidates = $uncounted
                    ->filter(function ($group, $k) use ($session, $date) {
                        [$v, $st, $c, $d] = explode('|', $k);

                        return (int) $v === (int) $session->vendor_id && (int) $st === (int) $session->store_id
                            && (int) $c === (int) $session->cashier_id
                            && $d < $date && $d >= CarbonImmutable::parse($date)->subDays(self::LOOKBACK_DAYS)->toDateString();
                    })
                    ->map(function ($group, $k) use ($expectation, $session, $countedCash, $countedTerminal) {
                        $d = explode('|', $k)[3];
                        $b = $expectation->compute((int) $session->vendor_id, (int) $session->store_id, (int) $session->cashier_id, $d, (float) $session->opening_float);

                        return [
                            'key' => $k, 'date' => $d, 'sales' => $group->count(),
                            'cash' => $b->expectedCash, 'terminal' => $b->expectedTerminal,
                            'gap' => abs($b->expectedCash - $countedCash) + abs($b->expectedTerminal - $countedTerminal),
                        ];
                    })
                    ->sortBy('gap');

                if ($candidates->isEmpty()) {
                    $this->line('  No uncounted day with sales in the '.self::LOOKBACK_DAYS.' days before it. Leave it and ask the cashier.');

                    continue;
                }

                foreach ($candidates as $c) {
                    $this->line(sprintf(
                        '  %s  %s: %d sales, expected cash %s / terminal %s — off by %s',
                        $c['gap'] < 1 ? 'MATCH' : '     ', $c['date'], $c['sales'],
                        $this->money($c['cash']), $this->money($c['terminal']), $this->money($c['gap']),
                    ));
                }

                $best = $candidates->first();

                if ($best['gap'] < 1) {
                    $claimed[$best['key']] = true;
                    $this->line("  Balances exactly on {$best['date']}. Suggested (dry run first):");
                    $this->line("    php artisan pos:redate-cash-up {$session->id} {$best['date']}");
                } else {
                    $this->line('  No day balances exactly. Do not move it on a guess — ask the cashier which day they counted.');
                }

                continue;
            }

            $now = $expectation->compute((int) $session->vendor_id, (int) $session->store_id, (int) $session->cashier_id, $date, (float) $session->opening_float);
            $cashGap = round($now->expectedCash - (float) $session->expected_cash, 2);
            $terminalGap = round($now->expectedTerminal - (float) $session->expected_terminal, 2);

            if (abs($cashGap) < 0.01 && abs($terminalGap) < 0.01) {
                continue;
            }

            $findings++;
            $late = ($salesByDay[$key($session->vendor_id, $session->store_id, $session->cashier_id, $date)] ?? collect())
                ->filter(fn (PosSale $s) => $session->closed_at && $s->created_at > $session->closed_at);

            $this->newLine();
            $this->warn('OUT OF DATE '.$this->describe($session));
            $this->line(sprintf(
                '  %d sale(s) reached the server after it was counted. Expected cash %s → %s, terminal %s → %s.',
                $late->count(),
                $this->money($session->expected_cash), $this->money($now->expectedCash),
                $this->money($session->expected_terminal), $this->money($now->expectedTerminal),
            ));
            $this->line(sprintf(
                '  Difference would be cash %s, terminal %s (frozen: %s, %s).',
                $this->money($now->cashVarianceAgainst((float) $session->counted_cash)),
                $this->money($now->terminalVarianceAgainst((float) $session->counted_terminal)),
                $this->money($session->cash_variance), $this->money($session->terminal_variance),
            ));

            if ($session->isPendingReview()) {
                $this->line("  Suggested (dry run first): php artisan pos:refresh-cash-up {$session->id}");
            } else {
                $this->line("  It is '{$session->status}', so it cannot be reworked. Correct it through the ledger.");
            }
        }

        $open = $uncounted->reject(fn ($g, $k) => isset($claimed[$k]) || explode('|', $k)[3] === $today);

        if ($open->isNotEmpty()) {
            $this->newLine();
            $this->warn('DAYS WITH SALES BUT NO END OF DAY RECORD');

            foreach ($open->sortKeys() as $k => $group) {
                $findings++;
                $first = $group->first();
                $this->line(sprintf(
                    '  %s  %s at %s: %d sales, %s — reached the server %s',
                    explode('|', $k)[3], $first->cashier?->name ?? 'cashier #'.$first->cashier_id,
                    $first->store?->name ?? 'store #'.$first->store_id, $group->count(),
                    $this->money($group->sum('total')), $this->lagos($group->max('created_at')),
                ));
            }

            $this->line('  Either a misfiled record above belongs here, or that day was never counted — ask the cashier.');
        }

        $this->newLine();
        $findings === 0
            ? $this->info('Nothing found. Every counted day matches its sales.')
            : $this->info("{$findings} finding(s). Nothing has been changed.");

        return self::SUCCESS;
    }

    private function describe(PosSession $s): string
    {
        return sprintf(
            '#%d %s — %s at %s (%s). Opened %s, counted %s. Counted cash %s / terminal %s, expected %s / %s.',
            $s->id, $s->business_date->toDateString(), $s->cashier?->name, $s->store?->name, $s->vendor?->name,
            $this->lagos($s->opened_at), $this->lagos($s->closed_at),
            $this->money($s->counted_cash), $this->money($s->counted_terminal),
            $this->money($s->expected_cash), $this->money($s->expected_terminal),
        );
    }

    private function money($value): string
    {
        return '₦'.number_format((float) $value, 2);
    }

    private function lagos($at): string
    {
        return $at ? CarbonImmutable::parse($at)->setTimezone(config('reporting.timezone', 'Africa/Lagos'))->format('d M H:i') : '—';
    }
}
