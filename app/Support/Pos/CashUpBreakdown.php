<?php

declare(strict_types=1);

namespace App\Support\Pos;

/**
 * What the drawer and the terminal should hold, and the working that got there.
 *
 * The lines are the point. A cashier told only "you are short ₦8,000" has no way
 * to check the claim, and the first question any of them asks is why the drawer
 * does not simply equal the day's sales. So each leg carries its arithmetic —
 * the float that started the day, the takings, the refunds paid back out, the
 * money spent or handed over — in the order it is applied, summing to the
 * expected figure. Show the lines, not just the answer.
 *
 * Immutable, and snapshotted onto the session at close: this is what the system
 * claimed at the moment the cashier was held to it. A sale syncing tomorrow must
 * not quietly rewrite the sum somebody already signed.
 */
final class CashUpBreakdown
{
    /**
     * @param  list<array{key: string, label: string, amount: float}>  $cashLines
     * @param  list<array{key: string, label: string, amount: float}>  $terminalLines
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly float $expectedCash,
        public readonly float $expectedTerminal,
        public readonly array $cashLines,
        public readonly array $terminalLines,
        public readonly array $context,
    ) {}

    /** Counted minus expected. Negative is short, positive is over. */
    public function cashVarianceAgainst(float $counted): float
    {
        return round($counted - $this->expectedCash, 2);
    }

    public function terminalVarianceAgainst(float $counted): float
    {
        return round($counted - $this->expectedTerminal, 2);
    }

    /**
     * Things a manager should see before trusting the figures.
     *
     * Not errors — the reconciliation still computes — but each one means the
     * expected figure may be understated, and a shortage explained by a data gap
     * should never be put to a cashier as if it were missing money.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if (($this->context['sales_without_store'] ?? 0) > 0) {
            $count = $this->context['sales_without_store'];

            $warnings[] = "{$count} sale(s) this cashier rang today are not assigned to any branch, so they are not "
                .'counted in the figures above. Expected cash may be understated.';
        }

        if (($this->context['sales_without_completion_time'] ?? 0) > 0) {
            $count = $this->context['sales_without_completion_time'];

            $warnings[] = "{$count} sale(s) have no completion time and cannot be placed on a trading day, so they are "
                .'excluded. Expected cash may be understated.';
        }

        return $warnings;
    }

    /** The snapshot written to cash_up_sessions.breakdown. */
    public function toArray(): array
    {
        return [
            'expected_cash'     => $this->expectedCash,
            'expected_terminal' => $this->expectedTerminal,
            'cash_lines'        => $this->cashLines,
            'terminal_lines'    => $this->terminalLines,
            'context'           => $this->context,
            'warnings'          => $this->warnings(),
        ];
    }
}
