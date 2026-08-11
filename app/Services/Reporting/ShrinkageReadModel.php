<?php

namespace App\Services\Reporting;

use App\Models\AccountabilityLedgerEntry;
use App\Models\InventoryShortageCase;
use App\Services\ShortageCaseService;
use Carbon\CarbonInterface;

// The query half of the financial seam. The five-layer financial system is not
// built yet, so nothing here posts anywhere — this reports what the accountability
// ledger already knows, in the shape that system will need.
//
// Every figure is non-negative and carries an explicit direction, matching
// FinancialLedgerEntry's convention rather than introducing signed amounts as a
// second vocabulary. The full contract, including what each term means and what
// must exist before binding, is in docs/accountability-seam.md.
class ShrinkageReadModel
{
    public function __construct(private ShortageCaseService $cases) {}

    /**
     * Per-case figures.
     *
     * @return array{
     *     case_id: int, vendor_id: int, product_id: int, storekeeper_id: ?int,
     *     status: string, charged_cost: float, charged_margin: float, charge_total: float,
     *     recovered_total: float, recovered_cost: float, recovered_margin: float,
     *     shrinkage_loss_at_cost: float, margin_forgone: float, outstanding: float
     * }
     */
    public function forCase(InventoryShortageCase $case): array
    {
        $allocation = $this->cases->allocation($case);

        $recoveredTotal = round($allocation['recovered_cost'] + $allocation['recovered_margin'], 2);

        // A loss is only recognised once the value is genuinely gone — a case
        // written off outright, or charged and then abandoned. A charge still
        // being pursued is a receivable, not an expense, so it contributes
        // nothing here until its fate is known.
        $lossRecognised = in_array($case->status, ['written_off'], true);

        $shrinkageAtCost = $lossRecognised
            ? ($case->disposed_at !== null && $this->wasEverCharged($case)
                // Charged then abandoned: only the cost still unrecovered is lost.
                ? $allocation['unrecovered_cost']
                // Written off outright: the whole cost is lost.
                : round((float) $case->cost_component, 2))
            : 0.0;

        return [
            'case_id'        => $case->id,
            'vendor_id'      => $case->vendor_id,
            'product_id'     => $case->product_id,
            'storekeeper_id' => $case->charged_storekeeper_id,
            'status'         => $case->status,

            // COST-SENSITIVE — gate behind view_cost_price before display.
            'charged_cost'   => round((float) $case->cost_component, 2),
            'charged_margin' => round((float) $case->margin_component, 2),

            // Retail. Safe to show without the cost permission.
            'charge_total'   => round((float) $case->charge_amount, 2),

            'recovered_total'  => $recoveredTotal,
            'recovered_cost'   => $allocation['recovered_cost'],
            'recovered_margin' => $allocation['recovered_margin'],

            // The only figure that is a real expense. Direction 'out'.
            'shrinkage_loss_at_cost' => $shrinkageAtCost,

            // Reported so the picture is complete, but never an expense: margin
            // that was never earned cannot be lost.
            'margin_forgone' => $lossRecognised ? $allocation['unrecovered_margin'] : 0.0,

            'outstanding' => round(max($this->cases->outstandingFor($case), 0.0), 2),
        ];
    }

    /**
     * Store-wide totals over a period, keyed to what a P&L needs.
     *
     * @return array{
     *     shrinkage_loss_at_cost: float, recovered_cost: float, recovered_margin: float,
     *     net_shrinkage_at_cost: float, margin_forgone: float, outstanding_from_staff: float,
     *     direction: array<string, string>
     * }
     */
    public function forVendor(int $vendorId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $cases = InventoryShortageCase::query()
            ->forVendor($vendorId)
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get();

        $shrinkage       = 0.0;
        $recoveredCost   = 0.0;
        $recoveredMargin = 0.0;
        $marginForgone   = 0.0;

        foreach ($cases as $case) {
            $row = $this->forCase($case);

            $shrinkage       += $row['shrinkage_loss_at_cost'];
            $recoveredCost   += $row['recovered_cost'];
            $recoveredMargin += $row['recovered_margin'];
            $marginForgone   += $row['margin_forgone'];
        }

        return [
            // Direction 'out' — the expense.
            'shrinkage_loss_at_cost' => round($shrinkage, 2),

            // Direction 'in' — both reduce the loss rather than being income,
            // because no sale took place.
            'recovered_cost'   => round($recoveredCost, 2),
            'recovered_margin' => round($recoveredMargin, 2),

            // What the P&L should actually carry, once recoveries are applied.
            'net_shrinkage_at_cost' => round($shrinkage - $recoveredCost - $recoveredMargin, 2),

            'margin_forgone' => round($marginForgone, 2),

            // Still owed by staff. A receivable, not a P&L line.
            'outstanding_from_staff' => round((float) AccountabilityLedgerEntry::query()
                ->forVendor($vendorId)
                ->whereNotNull('storekeeper_id')
                ->sum('amount'), 2),

            'direction' => [
                'shrinkage_loss_at_cost' => 'out',
                'recovered_cost'         => 'in',
                'recovered_margin'       => 'in',
            ],
        ];
    }

    /** Did this case ever carry a charge, as opposed to being written off outright? */
    private function wasEverCharged(InventoryShortageCase $case): bool
    {
        return AccountabilityLedgerEntry::query()
            ->where('case_id', $case->id)
            ->where('entry_type', 'charge')
            ->exists();
    }
}
