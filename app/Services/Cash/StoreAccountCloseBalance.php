<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\CashSubmission;
use App\Models\InventoryLedger;
use App\Models\PhysicalStockCount;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\Procurement;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The value a branch sold in a period, against where that value went.
 *
 * One question, two sides. On the left, what customers were charged. On the
 * right, every place that value could legitimately have gone: money handed over
 * and acknowledged, money spent out of the till, money that settled straight to
 * the bank as card or transfer, and value that walked out on credit. What does
 * not appear on the right is the shortage, and because card, transfer and
 * credit cancel across the two sides, the only thing that can actually go
 * missing is cash — which is why this gap must equal the one Checkmate already
 * reports, and why it is checked rather than asserted.
 *
 * No new reconciliation arithmetic lives here. The cash leg, the confirmed
 * submissions, the till expenses and the shortage all come from
 * StoreReconciliation; this assembles them into a balance and adds the one
 * thing a cash reconciliation structurally cannot see.
 *
 * Two deliberate departures from how the rest of the reporting stack counts
 * money, both forced by what this screen is for:
 *
 *  - Value sold is VAT-INCLUSIVE, and it is pos_sales.total rather than the
 *    revenue figure SalesReportService produces. Revenue there excludes VAT on
 *    purpose — VAT is collected for the government, not earned — but the till
 *    took it, somebody has to hand it over, and a left side that ignored it
 *    would read as a shortage of exactly the VAT.
 *  - It is POS only. SalesReportService also counts online orders allocated to
 *    a branch, and no branch ever held that money in a drawer.
 *
 * Profit is deliberately absent. Money reconciliation asks where the cash went;
 * profit asks what the goods cost. Putting them on one page is how a shortage
 * ends up being argued about in terms of margin.
 */
class StoreAccountCloseBalance
{
    /**
     * Movements that are a sale leaving the shelf.
     *
     * 'dispatched' is here because an online order picked and sent from this
     * branch has left it just as finally as one rung at the till.
     */
    private const SALE_TYPES = ['pos_sale', 'online_sale', 'dispatched'];

    /**
     * Posted by signing off a count, and excluded from every figure here.
     *
     * These are corrections a count produced, not goods moving. Letting one into
     * "received" would mean last month's shortfall, once written off, quietly
     * reappeared as this month's stock — and letting it into "sold" would credit
     * the branch with a sale nobody made.
     *
     * Both types are here because this codebase counts stock two different ways:
     * 'count_adjustment' comes from a settlement stock count, 'audit_correction'
     * from the two-person blind count on the Inventory Count page. They are the
     * same kind of event and must be treated the same.
     */
    private const CORRECTION_TYPES = ['count_adjustment', 'audit_correction'];

    public function __construct(private readonly StoreReconciliation $reconciliation) {}

    /**
     * The whole close view for a branch and a period.
     *
     * Runs live every time. Freezing it is a separate, deliberate act, and what
     * gets frozen is a copy of exactly this.
     *
     * @return array<string, mixed>
     */
    public function for(
        Store $store,
        CarbonInterface $from,
        CarbonInterface $to,
        ?PhysicalStockCount $closingCount = null,
        ?PhysicalStockCount $openingCount = null,
    ): array {
        $checkmate = $this->reconciliation->forStore($store, $from, $to);
        $baseline  = OpeningBaseline::resolve($store, $openingCount);
        $balance   = $this->balance($store, $from, $to, $checkmate);

        return [
            'store'  => ['id' => $store->id, 'name' => $store->name],
            'period' => ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()],

            'opening' => [
                'source'     => $baseline->source,
                'carried'    => $baseline->isCarriedForward(),
                'count_id'   => $baseline->count?->id,
                'counted_at' => $baseline->count?->counted_at?->toDateTimeString(),
                'products'   => $baseline->quantities()->count(),
                'units'      => (int) $baseline->quantities()->sum(),
            ],

            'closing' => $closingCount ? [
                'count_id'   => $closingCount->id,
                'counted_at' => $closingCount->counted_at?->toDateTimeString(),
                'counted_by' => $closingCount->countedBy?->name,
                'status'     => $closingCount->status,
            ] : null,

            'balance'        => $balance,
            'checkmate'      => $this->agreement($balance, $checkmate),
            'debt_check'     => $this->debtCheck($store, $from, $to, $balance),
            'submissions'    => $this->submissions($checkmate, $this->submissionRows($store, $from, $to)),
            'count_variance' => $this->countVariance($store, $from, $to, $baseline, $closingCount),
            'procurement'    => $this->procurement($store, $from, $to),
            'stock_movement' => $this->stockMovement($store, $from, $to, $baseline, $closingCount),
        ];
    }

    /**
     * The two sides.
     *
     * Everything is netted of returns, on both sides and by the method the
     * money actually went back out by. A refund that came off the left without
     * coming off the tender it was paid back through would read as a shortage
     * of the refund.
     *
     * @param  array<string, mixed>  $checkmate
     * @return array<string, mixed>
     */
    private function balance(Store $store, CarbonInterface $from, CarbonInterface $to, array $checkmate): array
    {
        // Scoped on completed_at, which is what CashDrawer scopes the cash leg
        // on. Using a different date column on the two sides would put a sale
        // in one period and the cash it produced in another.
        $gross = round((float) PosSale::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to])
            ->sum('total'), 2);

        $refunds = $this->refundsByMethod($store, $from, $to);
        $tenders = $this->tendersByMethod($store, $from, $to);

        // The cash leg is taken straight from Checkmate rather than summed
        // again here. Its three fiddly rules — tendered minus change, the cash
        // half of a split, refunds paid back out — are exactly the kind of
        // thing a second copy gets subtly wrong, and this balance is only worth
        // anything if it lands on the same number the cashier is asked for.
        $cashNet = (float) $checkmate['cash']['takings'];
        $confirmed = (float) $checkmate['cash']['confirmed'];
        $tillExpenses = (float) $checkmate['cash']['till_expenses'];

        $card     = round($tenders['card'] - $refunds['card'], 2);
        $transfer = round($tenders['bank_transfer'] - $refunds['bank_transfer'], 2);
        // Store credit nets against the credit side for the same reason a cash
        // refund nets against cash: it is value handed back to the customer as
        // a claim on the business rather than as money.
        $debt     = round($tenders['debt'] - $refunds['store_credit'], 2);

        $valueSold = round($gross - $refunds['total'], 2);

        $right = round($confirmed + $tillExpenses + $card + $transfer + $debt, 2);

        return [
            // LEFT — what customers were charged, less what went back to them.
            'value_sold'   => $valueSold,
            'gross_sales'  => $gross,
            'refunds'      => $refunds['total'],
            'refunds_by_method' => [
                'cash'          => $refunds['cash'],
                'card'          => $refunds['card'],
                'bank_transfer' => $refunds['bank_transfer'],
                'store_credit'  => $refunds['store_credit'],
            ],

            // RIGHT — every place that value could legitimately have gone.
            'submitted_total' => round($confirmed, 2),
            'till_expenses'   => round($tillExpenses, 2),
            'card'            => $card,
            'bank_transfer'   => $transfer,
            'period_debt'     => $debt,
            'right_total'     => $right,

            // Positive is short, negative is over — the same sign convention
            // StoreReconciliation uses, so the two never read opposite.
            'shortage' => round($valueSold - $right, 2),
            'overage'  => $valueSold - $right < 0 ? round(abs($valueSold - $right), 2) : 0.0,

            // Shown so the cash line on the right is readable: what the tills
            // took, against how much of it has been acknowledged back.
            'cash_taken' => round($cashNet, 2),
            'cash_expected' => (float) $checkmate['cash']['expected'],
        ];
    }

    /**
     * Does the balance land on the shortage Checkmate already reports.
     *
     * Checked, never assumed. Card, transfer and credit appear on both sides
     * and cancel, so the gap should reduce to cash alone — and if it does not,
     * something upstream is not what this arithmetic believes it is, which is
     * worth seeing rather than quietly presenting as a shortage.
     *
     * The comparison is against cash.shortage, not outstanding.true_shortage.
     * The latter is a narrower question — what is late or contested, after a
     * grace window and with cash still in transit set aside — and no gross
     * balance can equal it.
     *
     * @param  array<string, mixed>  $balance
     * @param  array<string, mixed>  $checkmate
     * @return array<string, mixed>
     */
    private function agreement(array $balance, array $checkmate): array
    {
        $cashShortage = (float) $checkmate['cash']['shortage'];
        $difference = round((float) $balance['shortage'] - $cashShortage, 2);

        return [
            'cash_shortage' => $cashShortage,
            // What is left once cash in transit and the grace window have had
            // their say. Shown beside the balancing figure, never instead of it.
            'true_shortage' => (float) $checkmate['outstanding']['true_shortage'],
            'agrees'        => abs($difference) < 0.01,
            'difference'    => $difference,
        ];
    }

    /**
     * What the shelf was worth at the start, what was bought into it, and what
     * it was worth at the end — so the difference is what actually left.
     *
     * Valued at COST throughout, and it has to be: a purchase has no selling
     * price, only what was paid for it. That makes this the one block on the
     * page not in selling-price naira, which is why it is kept apart from the
     * balance above and never added to it. The two answer different questions —
     * the balance asks where the money went, this asks what happened to the
     * goods.
     *
     * It is not a profit figure and must not be read as one. "Left the shelf, at
     * cost" is what it cost to buy the things that are gone; comparing it to the
     * value sold above would be mixing a cost with a price, and the gap between
     * them is not margin because neither side is scoped to the same goods.
     *
     * @return array<string, mixed>
     */
    private function stockMovement(
        Store $store,
        CarbonInterface $from,
        CarbonInterface $to,
        OpeningBaseline $baseline,
        ?PhysicalStockCount $closingCount,
    ): array {
        $opening = $this->countAtCost($baseline->count);
        $closing = $this->countAtCost($closingCount);

        $purchases = Procurement::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'procurements.supplier_id')
            ->where('procurements.store_id', $store->id)
            ->where('procurements.status', '!=', 'voided')
            ->whereBetween('procurements.created_at', [$from, $to])
            ->orderBy('procurements.created_at')
            ->get([
                'procurements.id', 'procurements.reference', 'procurements.total_cost',
                'procurements.payment_method', 'procurements.status',
                'procurements.created_at', 'suppliers.name as supplier',
            ])
            ->map(fn ($p) => [
                'id'        => $p->id,
                'date'      => $p->created_at?->format('d M Y'),
                'reference' => $p->reference,
                'supplier'  => $p->supplier ?? 'No supplier recorded',
                'amount'    => round((float) $p->total_cost, 2),
                'status'    => $p->status,
                // Surfaced rather than buried. Stock is normally paid for from
                // the business account; one paid in cash is the case that could
                // legitimately have come out of the till, and if it did it
                // belongs on the money side as a declared till expense. Nothing
                // in the data says which, so it is flagged for a person to
                // answer rather than assumed either way.
                'paid_cash' => $p->payment_method === 'cash',
            ]);

        $purchasesValue = round((float) $purchases->sum('amount'), 2);
        $availableValue = round($opening['value'] + $purchasesValue, 2);

        return [
            // Both counts are needed before the arithmetic means anything: with
            // no closing figure, "what left" would just be everything.
            'available' => $baseline->count !== null && $closingCount !== null,

            'opening_value' => $opening['value'],
            'opening_units' => $opening['units'],

            'purchases_value' => $purchasesValue,
            'purchases_count' => $purchases->count(),
            'purchases'       => $purchases->all(),

            'available_value' => $availableValue,

            'closing_value' => $closing['value'],
            'closing_units' => $closing['units'],

            // Opening plus what came in, less what is still there.
            'left_at_cost' => round($availableValue - $closing['value'], 2),

            // Units with no cost price recorded are left out of the value rather
            // than valued at nothing, so the total is visibly understated rather
            // than quietly wrong. Same rule the stock position panel follows.
            'uncosted_lines' => $opening['uncosted'] + $closing['uncosted'],

            'basis' => 'At cost, not selling price — a purchase has no selling price, only what was paid for it. '
                . 'Do not compare this with the value sold above: that is what customers were charged, and the '
                . 'difference between the two is not profit.',
        ];
    }

    /**
     * What a count was worth at cost, and how much of it could not be valued.
     *
     * @return array{units: int, value: float, uncosted: int}
     */
    private function countAtCost(?PhysicalStockCount $count): array
    {
        if (! $count) {
            return ['units' => 0, 'value' => 0.0, 'uncosted' => 0];
        }

        $lines = $count->relationLoaded('lines') ? $count->lines : $count->lines()->get();

        return [
            'units'    => (int) $lines->sum('counted_quantity'),
            'value'    => round((float) $lines->sum(
                fn ($l) => (int) $l->counted_quantity * (float) ($l->unit_cost ?? 0),
            ), 2),
            'uncosted' => $lines->filter(fn ($l) => $l->unit_cost === null)->count(),
        ];
    }

    /**
     * Every handover in the period, named and dated.
     *
     * A total says a branch is short; this says who handed what over and when,
     * which is the only form the conversation can actually take.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function submissionRows(Store $store, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return CashSubmission::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [$from, $to])
            ->with(['submitter:id,name', 'receiver:id,name'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (CashSubmission $s) => [
                'id'        => $s->id,
                'date'      => $s->created_at?->format('d M Y'),
                'reference' => $s->reference,
                'from'      => $s->submitter?->name ?? 'Unknown',
                'to'        => $s->receiver?->name ?? 'Not yet received',
                'amount'    => round((float) $s->amount, 2),
                'status'    => $s->status,
                // Only a confirmed or settled handover counts on the right of
                // the balance. One still pending is real money that has moved
                // and is nonetheless still sitting inside the shortage.
                'counts'    => in_array($s->status, [
                    CashSubmission::STATUS_CONFIRMED,
                    CashSubmission::STATUS_RESOLVED,
                ], true),
            ]);
    }

    /** @param  array<string, mixed>  $checkmate */
    private function submissions(array $checkmate, ?Collection $rows = null): array
    {
        return [
            'rows'      => $rows?->all() ?? [],
            'count'     => $rows?->count() ?? 0,
            'confirmed' => (float) $checkmate['cash']['confirmed'],
            // Warned about rather than blocked on. Money waiting on a receiver
            // is nobody's problem yet, but closing without seeing it is how a
            // period gets signed off blind.
            'pending'   => (float) $checkmate['cash']['pending'],
            'disputed'  => (float) $checkmate['cash']['disputed_claimed'],
            'unsubmitted' => (float) $checkmate['outstanding']['unsubmitted'],
        ];
    }

    /**
     * What the tills took, split by how the customer paid.
     *
     * Two sources, because a plain sale carries its tender on the sale row and
     * has no payment row at all, while a split and a credit sale both write
     * one. Reading only pos_sale_payments would miss every ordinary card sale.
     *
     * Cash is absent on purpose — it comes from Checkmate, which owns that
     * definition.
     *
     * @return array{card: float, bank_transfer: float, debt: float}
     */
    private function tendersByMethod(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $plain = PosSale::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to])
            ->whereIn('payment_method', ['card', 'bank_transfer'])
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COALESCE(SUM(total), 0) as amount')
            ->pluck('amount', 'payment_method');

        // Covers both halves of a split and a wholly-on-credit sale, which
        // writes a debt tender row precisely so that readers like this one do
        // not have to special-case the sale-level columns.
        $legs = PosSalePayment::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.pos_sale_id')
            ->where('pos_sales.store_id', $store->id)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween('pos_sales.completed_at', [$from, $to])
            ->whereIn('pos_sale_payments.method', ['card', 'bank_transfer', 'debt'])
            ->groupBy('pos_sale_payments.method')
            ->selectRaw('pos_sale_payments.method, COALESCE(SUM(pos_sale_payments.amount), 0) as amount')
            ->pluck('amount', 'method');

        return [
            'card'          => round((float) ($plain['card'] ?? 0) + (float) ($legs['card'] ?? 0), 2),
            'bank_transfer' => round((float) ($plain['bank_transfer'] ?? 0) + (float) ($legs['bank_transfer'] ?? 0), 2),
            'debt'          => round((float) ($legs['debt'] ?? 0), 2),
        ];
    }

    /**
     * Money handed back, by the route it went back out by.
     *
     * Dated by when the refund happened rather than when the original sale was
     * rung, matching how CashDrawer treats it: a refund paid this month for
     * last month's sale leaves this month's drawer.
     *
     * @return array<string, float>
     */
    private function refundsByMethod(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = PosReturn::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_returns.original_sale_id')
            ->where('pos_sales.store_id', $store->id)
            ->whereBetween('pos_returns.created_at', [$from, $to])
            ->groupBy('pos_returns.refund_method')
            ->selectRaw('pos_returns.refund_method, COALESCE(SUM(pos_returns.refund_amount), 0) as amount')
            ->pluck('amount', 'refund_method');

        $by = [
            'cash'          => round((float) ($rows['cash'] ?? 0), 2),
            'card'          => round((float) ($rows['card'] ?? 0), 2),
            'bank_transfer' => round((float) ($rows['bank_transfer'] ?? 0), 2),
            'store_credit'  => round((float) ($rows['store_credit'] ?? 0), 2),
        ];

        $by['total'] = round(array_sum($by), 2);

        return $by;
    }

    /**
     * The signal a cash reconciliation structurally cannot produce.
     *
     * Goods that left the shelf without a sale being rung leave the money side
     * balancing perfectly: expected cash is calculated from records that were
     * never made. Only counting the goods themselves sees it.
     *
     * Expected closing is opening plus what came in less what was rung, per
     * product. Anything else that moved stock — a transfer to another branch, a
     * picking, a manual adjustment — is deliberately NOT subtracted, because
     * the whole point is that movements which are not a receipt and not a sale
     * have to be explained rather than assumed. They are reported separately so
     * the explanation is in reach, and an 'unexplained' figure is given beside
     * the headline with those movements taken back out.
     *
     * @return array<string, mixed>
     */
    private function countVariance(
        Store $store,
        CarbonInterface $from,
        CarbonInterface $to,
        OpeningBaseline $baseline,
        ?PhysicalStockCount $closingCount,
    ): array {
        $blank = [
            'available'   => false,
            'approximate' => true,
            'basis'       => $this->valuationBasis(),
            'units'       => 0,
            'at_selling'  => 0.0,
            'unexplained_units' => 0,
            'unexplained_at_selling' => 0.0,
            'lines_short' => 0,
            'lines_over'  => 0,
            'top_offenders' => [],
            'snapshot_variance' => null,
        ];

        if (! $closingCount) {
            return $blank;
        }

        $opening = $baseline->quantities();
        $moves   = $this->movements($store, $from, $to);
        $lines   = $closingCount->lines()->with('product:id,name')->get();

        $rows = $lines->map(function ($line) use ($opening, $moves) {
            $productId = (int) $line->product_id;
            $move = $moves->get($productId, ['received' => 0, 'sold' => 0, 'other' => 0]);

            $openingUnits = (int) ($opening[$productId] ?? 0);
            $expected = $openingUnits + $move['received'] - $move['sold'];
            $variance = $expected - (int) $line->counted_quantity;

            // 'other' is negative for movements out, so adding it back removes
            // their effect: a transfer of three units to another branch stops
            // looking like three units stolen.
            $unexplained = $variance + $move['other'];

            $price = (float) ($line->unit_price ?? 0);

            return [
                'product_id'  => $productId,
                'product'     => $line->product?->name ?? ('Product #' . $productId),
                'opening'     => $openingUnits,
                'received'    => $move['received'],
                'sold'        => $move['sold'],
                'other_moves' => $move['other'],
                'expected'    => $expected,
                'counted'     => (int) $line->counted_quantity,
                'variance'    => $variance,
                'unexplained' => $unexplained,
                'unit_price'  => $price,
                'at_selling'  => round(max(0, $variance) * $price, 2),
                'unexplained_at_selling' => round(max(0, $unexplained) * $price, 2),
            ];
        });

        return [
            'available'   => true,
            // Said out loud everywhere this figure appears. Discounts and
            // mid-period price changes make it an estimate, and the recorded
            // sales beside it are the exact number.
            'approximate' => true,
            'basis'       => $this->valuationBasis(),

            'units'      => (int) $rows->sum('variance'),
            // Only what is MISSING is valued, never netted against an overage.
            // Finding extra of one product does not mean another was rung up,
            // and netting would quietly shrink the figure that is supposed to
            // explain a cash gap. Same rule PhysicalStockCount::variance uses.
            'at_selling' => round((float) $rows->sum('at_selling'), 2),

            'unexplained_units'      => (int) $rows->sum('unexplained'),
            'unexplained_at_selling' => round((float) $rows->sum('unexplained_at_selling'), 2),

            'lines_short' => $rows->where('variance', '>', 0)->count(),
            'lines_over'  => $rows->where('variance', '<', 0)->count(),
            'products_counted' => $rows->count(),

            // Which shelf to go and look at. A total says there is a problem.
            'top_offenders' => $rows
                ->filter(fn (array $r) => $r['variance'] !== 0)
                ->sortByDesc('at_selling')
                ->take(10)
                ->values()
                ->all(),

            // The count's own reading of itself, against the perpetual figure
            // frozen at the moment of counting rather than against a period
            // opening. Kept beside this one rather than replacing it: when the
            // two disagree, something moved stock that neither a sale nor a
            // receipt accounts for, and that disagreement is itself the signal.
            'snapshot_variance' => $closingCount->variance(),
        ];
    }

    /**
     * Per-product stock movement at this branch over the period.
     *
     * @return Collection<int, array{received: int, sold: int, other: int}>
     */
    private function movements(Store $store, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return InventoryLedger::query()
            ->where('store_id', $store->id)
            ->whereNotIn('transaction_type', self::CORRECTION_TYPES)
            ->whereBetween('created_at', [$from, $to])
            ->get(['product_id', 'transaction_type', 'quantity_change'])
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                $sales = $rows->whereIn('transaction_type', self::SALE_TYPES);
                $rest  = $rows->whereNotIn('transaction_type', self::SALE_TYPES);

                return [
                    // Anything arriving counts as received, whatever brought it:
                    // a delivery, a transfer in, a returned unit going back on
                    // the shelf. All of them are stock the branch now has to
                    // account for.
                    'received' => (int) $rest->where('quantity_change', '>', 0)->sum('quantity_change'),
                    'sold'     => (int) abs($sales->where('quantity_change', '<', 0)->sum('quantity_change')),
                    // Negative. Movements out that were neither a sale nor a
                    // return of stock — transfers away, pickings, adjustments.
                    'other'    => (int) $rest->where('quantity_change', '<', 0)->sum('quantity_change'),
                ];
            });
    }

    /**
     * Stock that came into the branch during the period.
     *
     * Quantities only, and emphatically not a money line. Procurement is paid
     * to a supplier out of the business's own accounts, not out of the till, so
     * putting its value on the right-hand side would net it against cash it
     * never touched and invent a shortage.
     *
     * @return array<string, mixed>
     */
    private function procurement(Store $store, CarbonInterface $from, CarbonInterface $to): array
    {
        $units = (int) InventoryLedger::query()
            ->where('store_id', $store->id)
            ->where('transaction_type', 'restock')
            ->whereBetween('created_at', [$from, $to])
            ->where('quantity_change', '>', 0)
            ->sum('quantity_change');

        return [
            'units_received' => $units,
            'batches' => Procurement::query()
                ->where('store_id', $store->id)
                ->where('status', '!=', 'voided')
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            // Carried on the row itself so no reader has to remember the rule.
            'is_money_line' => false,
            'note' => 'Stock received, in units. Feeds the count check only — procurement is paid from the business account, never the till.',
        ];
    }

    /**
     * Debt raised at this branch in the period, read from the customer ledger.
     *
     * Not what the balance uses — that takes the debt TENDER on the period's
     * own sales, which is the half of the two-sided view that cancels against
     * the left. This is the same money seen from the ledger's side, and the two
     * are reported together so a charge raised without a sale behind it, or a
     * sale whose credit never reached the ledger, shows up as the difference
     * rather than as a shortage.
     *
     * Payments against older debt are deliberately not here. They arrive as
     * cash and are already on the right through the confirmed submissions.
     */
    private function debtCheck(Store $store, CarbonInterface $from, CarbonInterface $to, array $balance): array
    {
        $ledger = $this->debtLedgerCharges($store, $from, $to);
        $difference = round((float) $balance['period_debt'] - $ledger, 2);

        return [
            'tender'         => (float) $balance['period_debt'],
            'ledger_charges' => $ledger,
            'agrees'         => abs($difference) < 0.01,
            'difference'     => $difference,
            // The ledger dates charges to a day, the tills to a second, so a
            // period boundary that falls mid-day can legitimately split a
            // charge from the sale that raised it. Worth seeing, not alarming.
            'note' => 'The customer ledger dates charges by day; sales are timed to the second.',
        ];
    }

    public function debtLedgerCharges(Store $store, CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) PosCustomerLedgerEntry::query()
            ->where('store_id', $store->id)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('amount'), 2);
    }

    private function valuationBasis(): string
    {
        return 'Approximate. Valued at each product\'s selling price as frozen on the count, '
            . 'not at the price any particular unit would have sold for — discounts and '
            . 'mid-period price changes make it an estimate. Recorded sales is the exact figure.';
    }
}
