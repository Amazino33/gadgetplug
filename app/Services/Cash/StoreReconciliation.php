<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\AccountabilityLedgerEntry;
use App\Models\CashSubmission;
use App\Models\Expense;
use App\Models\PosSalePayment;
use App\Models\PosSaleReversal;
use App\Models\Procurement;
use App\Models\Store;
use App\Models\TillExpense;
use App\Models\Vendor;
use App\Services\Inventory\StoreStockMetrics;
use App\Services\Pickings\PickingLedger;
use App\Services\Pos\CustomerDebtService;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What should have come back from a branch, against what did.
 *
 * Two calculations that are never mixed:
 *
 *  - Profit is revenue against cost of goods, and it does not care who paid or
 *    whether they have paid yet. Goods left, cost booked at stock-out.
 *  - Expected cash is only the cash tender. Credit sales are not cash and may
 *    never be; card and transfer settle straight to the bank and never pass
 *    through anyone's hands. Putting either into the cash figure would accuse a
 *    storekeeper of being short of money that was never in their drawer.
 *
 * Everything here is derived live from the ledgers that already exist. Nothing
 * is stored until a statement is generated, and that snapshot is a copy of this
 * — never a substitute for it.
 */
class StoreReconciliation
{
    /**
     * How long a branch may sit on cash before it stops being "not yet handed
     * over" and starts being a question. Two days covers a weekend close and a
     * bank holiday; past that, nobody is still carrying it innocently.
     */
    public const GRACE_DAYS = 2;

    public function __construct(
        private readonly SalesReportService $sales,
        private readonly CustomerDebtService $debts,
    ) {}

    /**
     * Every branch of a vendor, side by side, each with the people behind it.
     *
     * Cash is held by a person at a place, so a vendor-wide total is not
     * something anybody can act on. This is the view that says which branch, and
     * then which cashier there, is carrying it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byBranch(Vendor $vendor, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $vendor->stores()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function (Store $store) use ($from, $to) {
                $figures = $this->forStore($store, $from, $to);

                return [
                    'store_id'    => $store->id,
                    'store_name'  => $store->name,
                    'collected'   => $figures['cash']['takings'],
                    'spent'       => $figures['cash']['till_expenses'],
                    'expected'    => $figures['cash']['expected'],
                    'confirmed'   => $figures['cash']['confirmed'],
                    'pending'     => $figures['outstanding']['pending_confirmation'],
                    'outstanding' => $figures['outstanding']['unsubmitted'],
                    'disputed'    => $figures['outstanding']['disputed'],
                    'unexplained' => $figures['outstanding']['true_shortage'],
                    // Already resolved by forStore(); taking it from there keeps
                    // this to one pass per branch.
                    'cashiers'    => collect($figures['cashiers']),
                ];
            })
            // A branch that neither took nor owes anything in the window is
            // noise on a page about where money is.
            ->filter(fn (array $row) => abs($row['collected']) > 0.009
                || abs($row['confirmed']) > 0.009
                || abs($row['outstanding']) > 0.009)
            ->values();
    }

    public function forStore(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $profit = $this->sales->summary($store->vendor_id, $from, $to, $store->id);
        $cash   = $this->cash($store, $from, $to);

        $figures = [
            'store'  => ['id' => $store->id, 'name' => $store->name],
            'period' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],

            'profit' => [
                'revenue' => round((float) $profit['revenue'], 2),
                'cogs'    => round((float) $profit['cost'], 2),
                'profit'  => round((float) $profit['profit'], 2),
            ],

            'cash'        => $cash,
            'outstanding' => $this->outstanding($store, $cash, $to),
            'reversals'   => $this->reversals($store, $from, $to),

            // Frozen into the statement alongside the totals, because the
            // signoff is a conversation with named people — "the branch is
            // short" is not something anybody can answer for.
            'cashiers'    => CashDrawer::cashiersAt($store->vendor_id, $store->id, $from, $to)->all(),
        ];

        // Built last because it reads the figures above rather than recomputing
        // them — the panel and the reconciliation can never disagree.
        $figures['position'] = $this->position($store, $from, $to, $figures);

        return $figures;
    }

    /**
     * What this branch is holding or is owed, in money.
     *
     * Wider than the cash reconciliation above and answering a different
     * question: not "did the right amount come back" but "what is tied up here".
     * Every figure is read from the service that already owns it, so this panel
     * can never disagree with the screen that figure has its own page on.
     *
     * One deliberate impurity: pickings are valued at selling price, because
     * that is what PickingLedger has always reported and what the pickings
     * screen shows. Every other line is at cost. The label on the row says so —
     * a total that silently mixed the two would be worse than one that admits it.
     */
    public function position(Store $store, CarbonInterface $from, CarbonInterface $to, array $figures): array
    {
        $stock = StoreStockMetrics::forStores([$store->id])->get($store->id)
            ?? StoreStockMetrics::empty();

        $purchases = (float) Procurement::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', 'voided')
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_cost');

        $expenses = (float) Expense::query()
            ->where('store_id', $store->id)
            ->whereBetween('incurred_at', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $pickings = PickingLedger::heldTotals($store->vendor_id, $store->id);

        // Nullable store_id: entries recorded before branches existed belong to
        // no branch and are therefore absent here rather than counted against
        // whichever one happens to be open.
        $staffDebts = round((float) AccountabilityLedgerEntry::query()
            ->forVendor($store->vendor_id)
            ->forStore($store->id)
            ->sum('amount'), 2);

        // Money the tills took that has not been acknowledged back yet. Clamped
        // at zero: handing over more than was taken is an overage, and an
        // overage is not something the branch is holding.
        $cashOutstanding = max(0.0, round(
            (float) $figures['cash']['expected'] - (float) $figures['cash']['confirmed'],
            2,
        ));

        $debt = (float) $figures['outstanding']['unpaid_debt']['total'];

        return [
            'stock_at_cost'     => round((float) $stock->cost_value, 2),
            'stock_units'       => (int) $stock->units,
            // Stock with no cost price is left out of the value rather than
            // valued at nothing, so the total is visibly understated.
            'stock_uncosted'    => (int) $stock->missing_cost_count,

            'sold_in_range'     => (float) $figures['profit']['revenue'],
            'cogs_in_range'     => (float) $figures['profit']['cogs'],
            'profit_in_range'   => (float) $figures['profit']['profit'],

            'purchases'         => round($purchases, 2),
            'expenses'          => round($expenses + (float) $figures['cash']['till_expenses'], 2),

            'cash_outstanding'  => $cashOutstanding,
            'customer_debt'     => $debt,
            'pickings_retail'   => round((float) $pickings['value'], 2),
            'pickings_units'    => (int) $pickings['units'],
            'staff_debts'       => max(0.0, $staffDebts),

            'total_balance'     => round(
                (float) $stock->cost_value
                + $cashOutstanding
                + $debt
                + (float) $pickings['value']
                + max(0.0, $staffDebts),
                2,
            ),
        ];
    }

    /**
     * The cash side: what the tills took, less what was spent out of them,
     * against what has been handed over and acknowledged.
     */
    private function cash(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $takings = CashDrawer::takingsIn($store->vendor_id, $store->id, from: $from, to: $to);

        $expenses = (float) TillExpense::query()
            ->forStore($store->id)
            ->spentBetween($from, $to)
            ->sum('amount');

        // Declared spending is money that genuinely left for the business's own
        // purposes. Netting it off here rather than showing it as a shortage is
        // the entire reason the expense log exists.
        $expected = round($takings - $expenses, 2);

        $submissions = CashSubmission::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $confirmed = $this->sum($submissions, CashSubmission::STATUS_CONFIRMED);
        $pending   = $this->sum($submissions, CashSubmission::STATUS_PENDING);
        $disputed  = $this->sum($submissions, CashSubmission::STATUS_DISPUTED);

        // On a dispute the two parties disagree about what arrived. The
        // receiver's figure is what the business can actually count; the
        // difference is contested and settled by people, not by arithmetic.
        $disputedReceived = round($submissions
            ->where('status', CashSubmission::STATUS_DISPUTED)
            ->sum(fn (CashSubmission $s) => (float) ($s->disputed_amount ?? 0)), 2);

        return [
            'takings'          => $takings,
            'till_expenses'    => round($expenses, 2),
            'expected'         => $expected,

            'confirmed'        => $confirmed,
            'pending'          => $pending,
            'disputed_claimed' => $disputed,
            'disputed_received' => $disputedReceived,

            'submitted_total'  => round($confirmed + $pending + $disputed, 2),

            // Positive means short: less came back than should have.
            'shortage'         => round($expected - $confirmed, 2),
        ];
    }

    /**
     * The gap, broken into things somebody can act on.
     *
     * The point of separating these is that they call for different actions and
     * carry different levels of blame. Money a customer has not paid yet is not
     * a storekeeper's problem; money that was handed over and is waiting on a
     * receiver is nobody's problem yet; money that was never handed over and is
     * now overdue is somebody's problem specifically.
     */
    private function outstanding(Store $store, array $cash, CarbonInterface $to): array
    {
        // Never handed over in any form. Negative would mean more was submitted
        // than the tills took, which is an overage, not a debt.
        $unsubmitted = round($cash['expected'] - $cash['submitted_total'], 2);

        // Unsubmitted money only becomes a question once there has been time to
        // hand it over. Judged on the period's own end date: if that was longer
        // ago than the grace window, anything still unsubmitted from it is late,
        // and a period that ended yesterday accuses nobody of anything yet.
        $overdue = $unsubmitted > 0 && Carbon::parse($to)->addDays(self::GRACE_DAYS)->isPast()
            ? $unsubmitted
            : 0.0;

        $disputedGap = round($cash['disputed_claimed'] - $cash['disputed_received'], 2);

        $debt = $this->debts->aged($store->vendor_id, $store->id, $to);

        return [
            // Not cash yet, and may never be. Shrinks on its own as customers
            // pay, because it is read from the debt ledger rather than copied.
            // Carries its own aging, so "owed" and "owed since March" are not
            // the same line on the statement.
            'unpaid_debt'          => [
                'total'   => $debt['total'],
                'buckets' => $debt['buckets'],
                'debtors' => $debt['debtors'],
            ],

            'unsubmitted'          => max(0.0, $unsubmitted),
            'overage'              => $unsubmitted < 0 ? abs($unsubmitted) : 0.0,
            'pending_confirmation' => $cash['pending'],
            'disputed'             => $disputedGap,

            // What is left once every other bucket has spoken for itself:
            // cash that is late with nobody claiming to have moved it, plus
            // amounts two people cannot agree on. This is the Checkmate number.
            'true_shortage'        => round($overdue + $disputedGap, 2),
        ];
    }

    /** Sales withdrawn during the period — visible, not silently absent. */
    private function reversals(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = PosSaleReversal::query()
            ->forStore($store->id)
            ->between($from, $to)
            ->with('sale:id,reference,payment_method,total')
            ->get();

        // A voided cash sale removes money from expected cash after the fact.
        // Shown on its own line because that is indistinguishable, in the
        // totals alone, from the money simply never having been taken.
        $voidedCash = $rows
            ->where('type', PosSaleReversal::TYPE_VOID)
            ->filter(fn ($r) => in_array($r->sale?->payment_method, ['cash', 'split'], true));

        // The reversal's amount is the whole sale. On a split only the cash leg
        // ever sat in the drawer, so counting the card half here would overstate
        // what the void actually removed from expected cash.
        $splitCashLegs = PosSalePayment::query()
            ->whereIn('pos_sale_id', $voidedCash->where('sale.payment_method', 'split')->pluck('pos_sale_id'))
            ->where('method', 'cash')
            ->groupBy('pos_sale_id')
            ->selectRaw('pos_sale_id, SUM(amount) as cash')
            ->pluck('cash', 'pos_sale_id');

        $voidedCashValue = $voidedCash->sum(fn (PosSaleReversal $r) => $r->sale?->payment_method === 'split'
            ? (float) ($splitCashLegs[$r->pos_sale_id] ?? 0)
            : (float) $r->amount);

        return [
            'count'            => $rows->count(),
            'voided_cash'      => round((float) $voidedCashValue, 2),
            'voided_cash_count' => $voidedCash->count(),
            'unexplained'      => $rows->where('reason', 'No reason given')->count(),
        ];
    }

    private function sum($submissions, string $status): float
    {
        return round((float) $submissions->where('status', $status)->sum('amount'), 2);
    }
}
