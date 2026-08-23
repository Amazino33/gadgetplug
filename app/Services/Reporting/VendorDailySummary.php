<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Expense;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\Procurement;
use App\Models\Store;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// One day of trading, reduced to the numbers an owner actually asks about:
// what each branch sold, what should be in the till versus the bank, and what
// was spent.
//
// Two different money definitions appear side by side on purpose, because
// conflating them is what makes a summary untrustworthy:
//
//   "money taken" — gross, INCLUDING VAT, split by how it was paid. This is the
//                   physical reconciliation figure: what should be in the cash
//                   drawer and what should have hit the bank.
//   "revenue"     — net of discount, EXCLUDING VAT, the figure the P&L earns on.
//                   VAT is collected for the government, not earned.
//
// Sales figures come from StoreSalesQuery and FinancialReportService, so every
// number matches what the same vendor sees in the app. Nothing is recomputed
// with a private definition — a WhatsApp figure that disagrees with the Reports
// page is worse than no WhatsApp figure at all.
class VendorDailySummary
{
    public function __construct(private FinancialReportService $financials) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Vendor $vendor, CarbonInterface $date): array
    {
        $from = $date->copy()->startOfDay();
        $to   = $date->copy()->endOfDay();

        $vendorTotals = StoreSalesQuery::totals($vendor->id, null, $from, $to);
        $stores       = $this->storeRows($vendor, $from, $to);
        $payments     = $this->paymentBreakdown($vendor->id, $from, $to);
        $pnl          = $this->financials->report($vendor->id, $from, $to);
        $expenses     = $this->expensesRecorded($vendor->id, $date);
        $procurement  = $this->procurementRecorded($vendor->id, $date);

        // Online lines can reach revenue recognition without a store allocation
        // (see StoreSalesQuery::onlineTotals), so branches can legitimately sum
        // to less than the vendor total. Surfaced rather than hidden: an owner
        // who adds up the branch lines and finds a shortfall should see the gap
        // named, not be left assuming a branch under-reported.
        $attributed   = array_sum(array_column($stores, 'revenue'));
        $unattributed = max(0.0, $vendorTotals['revenue'] - $attributed);

        return [
            'vendor'       => $vendor,
            'date'         => $date,
            'stores'       => $stores,
            'unattributed' => $unattributed,
            'totals'       => $vendorTotals,
            'payments'     => $payments,
            'pnl'          => $pnl,
            'expenses'     => $expenses,
            'procurement'  => $procurement,
            // Nothing sold, nothing spent, nothing bought. Lets the caller skip a
            // message whose only content would be zero repeated six times.
            'is_empty'     => $vendorTotals['orders'] === 0
                && $expenses['total'] <= 0
                && $procurement['count'] === 0,
        ];
    }

    /**
     * @return array<int, array{name: string, orders: int, units: int, revenue: float}>
     */
    private function storeRows(Vendor $vendor, CarbonInterface $from, CarbonInterface $to): array
    {
        return Store::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function (Store $store) use ($vendor, $from, $to): array {
                $totals = StoreSalesQuery::totals($vendor->id, $store->id, $from, $to);

                return [
                    'name'    => $store->name,
                    'orders'  => $totals['orders'],
                    'units'   => $totals['units'],
                    'revenue' => $totals['revenue'],
                ];
            })
            ->all();
    }

    /**
     * What was actually collected, by tender type, gross of VAT.
     *
     * Split sales are why this is not one GROUP BY. A sale paid part cash part
     * transfer carries payment_method = 'split', and its real tender mix lives
     * in pos_sale_payments. Grouping pos_sales.payment_method alone would drop
     * every split sale into a phantom "split" bucket and understate both the
     * cash drawer and the bank — exactly the shape of bug that makes an owner
     * stop trusting the number.
     *
     * @return array{cash: float, card: float, bank_transfer: float, total: float}
     */
    private function paymentBreakdown(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $completedAt = DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)');

        $direct = PosSale::query()
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->where('pos_sales.payment_method', '!=', 'split')
            ->whereBetween($completedAt, [$from, $to])
            ->groupBy('pos_sales.payment_method')
            ->selectRaw('pos_sales.payment_method as method')
            ->selectRaw('COALESCE(SUM(pos_sales.total), 0) as amount')
            ->pluck('amount', 'method');

        $split = PosSalePayment::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->where('pos_sales.payment_method', 'split')
            ->whereBetween($completedAt, [$from, $to])
            ->groupBy('pos_sale_payments.method')
            ->selectRaw('pos_sale_payments.method as method')
            ->selectRaw('COALESCE(SUM(pos_sale_payments.amount), 0) as amount')
            ->pluck('amount', 'method');

        $totals = [];

        foreach (['cash', 'card', 'bank_transfer'] as $method) {
            $totals[$method] = (float) ($direct[$method] ?? 0) + (float) ($split[$method] ?? 0);
        }

        $totals['total'] = array_sum($totals);

        return $totals;
    }

    /**
     * Expenses entered for that day, by incurred_at — deliberately not the
     * cash-basis posted_at the P&L uses. The owner asked what was recorded, and
     * an expense logged but not yet paid would otherwise read as zero and look
     * like nothing was spent.
     *
     * @return array{total: float, by_category: array<string, float>}
     */
    private function expensesRecorded(int $vendorId, CarbonInterface $date): array
    {
        $rows = Expense::query()
            ->where('vendor_id', $vendorId)
            ->whereDate('incurred_at', $date->toDateString())
            ->groupBy('category')
            ->selectRaw('category')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->pluck('amount', 'category');

        $byCategory = [];

        foreach ($rows as $category => $amount) {
            $byCategory[(string) $category] = (float) $amount;
        }

        return [
            'total'       => array_sum($byCategory),
            'by_category' => $byCategory,
        ];
    }

    /**
     * Procurements raised that day. Voided ones are excluded — a voided purchase
     * is a correction, and counting it would tell the owner they committed money
     * they did not.
     *
     * @return array{count: int, total_cost: float, amount_paid: float, outstanding: float}
     */
    private function procurementRecorded(int $vendorId, CarbonInterface $date): array
    {
        $row = Procurement::query()
            ->where('vendor_id', $vendorId)
            ->where('status', '!=', 'voided')
            ->whereDate('created_at', $date->toDateString())
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COALESCE(SUM(total_cost), 0) as total_cost')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as amount_paid')
            ->first();

        $totalCost  = (float) $row->total_cost;
        $amountPaid = (float) $row->amount_paid;

        return [
            'count'       => (int) $row->row_count,
            'total_cost'  => $totalCost,
            'amount_paid' => $amountPaid,
            'outstanding' => max(0.0, $totalCost - $amountPaid),
        ];
    }
}
