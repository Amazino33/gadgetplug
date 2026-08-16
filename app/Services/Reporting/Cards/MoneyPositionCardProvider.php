<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Filament\Vendor\Pages\FinancialReport;
use App\Models\Vendor;
use App\Services\Reporting\FinancialReportService;
use Carbon\CarbonImmutable;

// Reads FinancialReportService::report() as-is, called twice (this month,
// last month) — the same public method the financial report page itself
// calls, so Bank/Cash/net profit here can never drift from that page.
// Deliberately this-month profit with a comparison, not "today's profit":
// a single POD day is lumpy (a handful of large orders can swing it either
// way), so a daily figure would fail the "not the same reassuring number
// every day" bar by being noisy rather than meaningful.
class MoneyPositionCardProvider implements ReportCardProvider
{
    public function summarize(int $vendorId, ?int $storeId = null): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $reports = app(FinancialReportService::class);
        $now = CarbonImmutable::now();

        $thisMonth = $reports->report($vendorId, $now->startOfMonth(), $now);

        $lastMonthAnchor = $now->subMonthNoOverflow();
        $lastMonth = $reports->report($vendorId, $lastMonthAnchor->startOfMonth(), $lastMonthAnchor->endOfMonth());

        $bank = $thisMonth['balances']['bank'];
        $cash = $thisMonth['balances']['cash'];
        $profit = $thisMonth['net_profit'];
        $previousProfit = $lastMonth['net_profit'];

        $headline = 'Bank ₦' . number_format($bank, 2) . ' · Cash ₦' . number_format($cash, 2);

        return new CardSummary(
            key: 'money_position',
            title: 'Money Position',
            headline: $headline,
            comparison: 'Net profit ₦' . number_format($profit, 2) . ' this month — ' . $this->profitComparison($profit, $previousProfit),
            comparisonDirection: $profit > $previousProfit ? 'up' : ($profit < $previousProfit ? 'down' : 'flat'),
            urgency: match (true) {
                $profit < 0 => CardSummary::URGENCY_URGENT,
                $profit < $previousProfit => CardSummary::URGENCY_ATTENTION,
                default => CardSummary::URGENCY_CALM,
            },
            link: FinancialReport::getUrl(panel: 'vendor', tenant: $vendor),
            // Bank and cash balances, expenses, procurement legs and delivery
            // costs are all vendor-level — there is no store dimension to
            // filter on. Rather than fabricate a split or silently ignore the
            // filter, the card says which scope it is actually reporting.
            vendorWideOnly: $storeId !== null,
        );
    }

    private function profitComparison(float $current, float $previous): string
    {
        if ($previous == 0.0) {
            return $current > 0.0 ? 'no profit recorded last month' : 'no profit recorded yet';
        }

        $change = (($current - $previous) / abs($previous)) * 100;

        return ($change >= 0 ? '+' : '') . number_format($change, 1) . '% vs last month';
    }
}
