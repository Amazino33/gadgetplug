<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Models\Vendor;
use App\Services\Reporting\FinancialReportService;
use Carbon\CarbonImmutable;

// Reads FinancialReportService::report() for the month — 'advertising' and
// 'revenue' are both already broken out there, so this card adds zero new
// money logic, just a ratio between two existing figures.
class AdEfficiencyCardProvider implements ReportCardProvider
{
    private const RATIO_URGENT = 25.0;
    private const RATIO_ATTENTION = 15.0;

    public function summarize(int $vendorId): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $now = CarbonImmutable::now();

        $report = app(FinancialReportService::class)->report($vendorId, $now->startOfMonth(), $now);

        $adSpend = $report['advertising'];
        $revenue = $report['revenue'];
        $ratio = $revenue > 0 ? ($adSpend / $revenue) * 100 : null;

        $headline = '₦' . number_format($adSpend, 2) . ' ad spend this month';

        $comparison = match (true) {
            $ratio !== null => number_format($ratio, 1) . '% of delivered revenue',
            $adSpend > 0 => 'No delivered revenue yet to compare against',
            default => 'No ad spend recorded this month',
        };

        $urgency = match (true) {
            // Spending with literally nothing delivered to show for it yet is
            // its own signal, independent of the ratio (which is undefined
            // at zero revenue).
            $adSpend > 0 && $revenue <= 0 => CardSummary::URGENCY_URGENT,
            $ratio !== null && $ratio >= self::RATIO_URGENT => CardSummary::URGENCY_URGENT,
            $ratio !== null && $ratio >= self::RATIO_ATTENTION => CardSummary::URGENCY_ATTENTION,
            default => CardSummary::URGENCY_CALM,
        };

        return new CardSummary(
            key: 'ad_efficiency',
            title: 'Ad Efficiency',
            headline: $headline,
            comparison: $comparison,
            urgency: $urgency,
            link: ExpenseResource::getUrl('index', panel: 'vendor', tenant: $vendor),
        );
    }
}
