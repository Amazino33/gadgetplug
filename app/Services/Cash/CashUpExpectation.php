<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\CashUpRectification;
use App\Models\CashUpSession;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Support\Pos\BusinessDate;
use App\Support\Pos\CashUpBreakdown;
use Illuminate\Support\Collection;

/**
 * What one cashier's drawer and terminal should hold at the end of a trading day.
 *
 * The single place these figures are worked out. The sync endpoint snapshots
 * them at close, the review screen reads the snapshot, and any later dashboard
 * asks here — because a second implementation is how a cashier ends up shown one
 * number on the till and accused with another in the office.
 *
 * Cash-basis and attributed to the cashier, per the locked formulas:
 *
 *   expected cash     = opening float + cash taken - cash refunded - cash paid out
 *   expected terminal = card and transfer taken - card and transfer refunded
 *
 * Deliberately keyed on cashier + store + business date rather than on a POS
 * session. Sales replayed through the offline sync endpoint are written with
 * pos_session_id = null, so a session-keyed sum silently omits every sale rung
 * while the till was offline — the exact sales most in need of reconciling.
 *
 * Distinct from CashDrawer, which answers a different question: that is a rolling
 * per-person balance of takings not yet handed over, and it deliberately ignores
 * the opening float because the float belongs to the shop. This is one day's
 * drawer, float included, because a cashier counting the drawer counts the float
 * along with everything else. The two must not be summed together.
 */
class CashUpExpectation
{
    /** Both card and transfer are taken on the cashier's own Moniepoint terminal. */
    private const TERMINAL_METHODS = ['card', 'bank_transfer'];

    /** Recompute a session's figures from its own opening float and rectifications. */
    public function for(CashUpSession $session): CashUpBreakdown
    {
        return $this->compute(
            vendorId: (int) $session->vendor_id,
            storeId: (int) $session->store_id,
            cashierId: (int) $session->cashier_id,
            businessDate: $session->business_date->toDateString(),
            openingFloat: (float) $session->opening_float,
            rectifications: $session->rectifications,
        );
    }

    /**
     * @param  iterable<CashUpRectification>  $rectifications
     */
    public function compute(
        int $vendorId,
        int $storeId,
        int $cashierId,
        string $businessDate,
        float $openingFloat,
        iterable $rectifications = [],
    ): CashUpBreakdown {
        [$from, $to] = BusinessDate::boundsFor($businessDate);

        $scope = fn () => PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('store_id', $storeId)
            ->where('cashier_id', $cashierId)
            // A voided sale took no money. Refunded and partially-refunded sales
            // did take money at the time; the refund is subtracted separately as
            // its own event, on the day it was actually paid back out.
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to]);

        $entries = Collection::make($rectifications);

        // ── Cash leg ─────────────────────────────────────────────────────────

        $cashLines = [[
            'key'    => 'opening_float',
            'label'  => 'Opening float',
            'amount' => round($openingFloat, 2),
        ]];

        // What actually stayed in the drawer, not the sale total: on a sale where
        // change was given, the total overstates the notes left behind.
        $cashSales = (float) $scope()
            ->where('payment_method', 'cash')
            ->selectRaw('COALESCE(SUM(amount_tendered - change_given), 0) as v')
            ->value('v');

        $cashLines[] = [
            'key'    => 'cash_sales',
            'label'  => 'Cash sales',
            'amount' => round($cashSales, 2),
        ];

        $splitCash = $this->splitTenderTotal($scope(), ['cash']);

        // Change on a mixed sale always comes out of the drawer, whatever the
        // rest of it was paid on. Subtracted once per sale rather than once per
        // tender row, which is what a join over the payments would do.
        $splitChange = (float) $scope()
            ->where('payment_method', 'split')
            ->sum('change_given');

        $splitCashNet = round($splitCash - $splitChange, 2);

        if (abs($splitCashNet) > 0.009) {
            $cashLines[] = [
                'key'    => 'split_cash',
                'label'  => 'Cash part of mixed-payment sales',
                'amount' => $splitCashNet,
            ];
        }

        $cashRefunds = $this->refundTotal($vendorId, $storeId, $cashierId, $from, $to, ['cash']);

        if ($cashRefunds > 0.009) {
            $cashLines[] = [
                'key'    => 'cash_refunds',
                'label'  => 'Refunds paid in cash',
                'amount' => round(-$cashRefunds, 2),
            ];
        }

        $cashLines = array_merge($cashLines, $this->rectificationLines($entries, CashUpRectification::LEG_CASH));

        // ── Terminal leg ─────────────────────────────────────────────────────

        $terminalLines = [];

        foreach (self::TERMINAL_METHODS as $method) {
            $direct = (float) $scope()->where('payment_method', $method)->sum('total');
            $split = $this->splitTenderTotal($scope(), [$method]);
            $taken = round($direct + $split, 2);

            $terminalLines[] = [
                'key'    => $method === 'card' ? 'card_sales' : 'transfer_sales',
                'label'  => $method === 'card' ? 'Card sales' : 'Transfer sales',
                'amount' => $taken,
            ];
        }

        $terminalRefunds = $this->refundTotal($vendorId, $storeId, $cashierId, $from, $to, self::TERMINAL_METHODS);

        if ($terminalRefunds > 0.009) {
            $terminalLines[] = [
                'key'    => 'terminal_refunds',
                'label'  => 'Refunds paid back on the terminal',
                'amount' => round(-$terminalRefunds, 2),
            ];
        }

        $terminalLines = array_merge($terminalLines, $this->rectificationLines($entries, CashUpRectification::LEG_TERMINAL));

        return new CashUpBreakdown(
            expectedCash: $this->sumLines($cashLines),
            expectedTerminal: $this->sumLines($terminalLines),
            cashLines: $cashLines,
            terminalLines: $terminalLines,
            context: $this->context($vendorId, $storeId, $cashierId, $from, $to, $scope),
        );
    }

    /**
     * The value of one or more tenders inside mixed-payment sales.
     *
     * Read off pos_sale_payments rather than the sale, because a split sale's
     * payment_method is 'split' and says nothing about where the money went.
     *
     * @param  list<string>  $methods
     */
    private function splitTenderTotal($scoped, array $methods): float
    {
        $saleIds = (clone $scoped)->where('payment_method', 'split')->select('id');

        return round((float) PosSalePayment::query()
            ->whereIn('pos_sale_id', $saleIds)
            ->whereIn('method', $methods)
            ->sum('amount'), 2);
    }

    /**
     * Money handed back out on a given tender, on the day it was handed back.
     *
     * Attributed to the cashier who processed the refund, not the one who made
     * the sale: it is their drawer the notes came out of. Dated by the refund
     * itself, so a refund today against last week's sale lands on today.
     *
     * pos_returns carries no store_id, so the branch is taken from the original
     * sale — the same join CashDrawer uses. A refund paid out at a different
     * branch than the sale would be attributed to the sale's branch; rare enough
     * to accept, and better than dropping the refund entirely.
     *
     * @param  list<string>  $methods
     */
    private function refundTotal(int $vendorId, int $storeId, int $cashierId, $from, $to, array $methods): float
    {
        return round((float) PosReturn::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_returns.original_sale_id')
            ->where('pos_returns.vendor_id', $vendorId)
            ->where('pos_returns.cashier_id', $cashierId)
            ->where('pos_sales.store_id', $storeId)
            ->whereIn('pos_returns.refund_method', $methods)
            ->whereBetween('pos_returns.created_at', [$from, $to])
            ->sum('pos_returns.refund_amount'), 2);
    }

    /**
     * One line per kind of rectification that moves this leg.
     *
     * Aggregated by kind rather than listed row by row: a cashier who logged nine
     * small expenses wants to see "money spent out of the drawer, ₦4,300", and
     * the individual rows are on the review screen underneath.
     *
     * @param  Collection<int, CashUpRectification>  $entries
     * @return list<array{key: string, label: string, amount: float}>
     */
    private function rectificationLines(Collection $entries, string $leg): array
    {
        $labels = [
            CashUpRectification::KIND_EXPENSE        => 'Spent out of the drawer',
            CashUpRectification::KIND_CASH_OUT       => 'Cash handed over',
            CashUpRectification::KIND_TENDER_RECLASS => 'Corrected — rung on the wrong tender',
            CashUpRectification::KIND_DEBT_PAID      => 'Corrected — credit sale actually paid',
        ];

        $lines = [];

        foreach ($labels as $kind => $label) {
            $effect = round(
                $entries->where('kind', $kind)->sum(fn (CashUpRectification $e) => $e->effectOn($leg)),
                2
            );

            if (abs($effect) > 0.009) {
                $lines[] = ['key' => $kind, 'label' => $label, 'amount' => $effect];
            }
        }

        return $lines;
    }

    /**
     * Figures that explain the day without belonging to either leg.
     *
     * Debt and store credit are the usual reason a cashier insists the drawer is
     * short: they sold ₦300,000 and the money is not there because a third of it
     * walked out on credit. Neither is money in anybody's hands, so neither is
     * reconciled — but both have to be visible or the figures look wrong.
     */
    private function context(int $vendorId, int $storeId, int $cashierId, $from, $to, callable $scope): array
    {
        $debtRung = round((float) PosSalePayment::query()
            ->whereIn('pos_sale_id', $scope()->select('id'))
            ->where('method', 'debt')
            ->sum('amount'), 2);

        $storeCredit = $this->refundTotal($vendorId, $storeId, $cashierId, $from, $to, ['store_credit']);

        // Sales this cashier rang today that no branch owns, and so that no
        // store-scoped cash-up can see. Should be zero — TillStore always
        // resolves a branch and history was backfilled — but a shortage caused by
        // a data gap must never be put to a cashier as missing money.
        $withoutStore = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('cashier_id', $cashierId)
            ->where('status', '!=', 'voided')
            ->whereNull('store_id')
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        // Likewise a sale that cannot be placed on a trading day at all.
        $withoutTime = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('store_id', $storeId)
            ->where('cashier_id', $cashierId)
            ->where('status', '!=', 'voided')
            ->whereNull('completed_at')
            ->count();

        return [
            'sales_count'                   => $scope()->count(),
            'gross_sales'                   => round((float) $scope()->sum('total'), 2),
            'debt_rung'                     => $debtRung,
            'store_credit_refunds'          => $storeCredit,
            'sales_without_store'           => $withoutStore,
            'sales_without_completion_time' => $withoutTime,
        ];
    }

    /** @param  list<array{key: string, label: string, amount: float}>  $lines */
    private function sumLines(array $lines): float
    {
        return round(array_sum(array_column($lines, 'amount')), 2);
    }
}
