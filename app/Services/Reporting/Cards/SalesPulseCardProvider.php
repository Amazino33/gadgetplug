<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Filament\Vendor\Pages\SalesReport;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\Reporting\FinancialReportService;
use Carbon\CarbonImmutable;

// "Delivered today" and its revenue read FinancialReportService's shared
// recognizedOrderItemsQuery() — the exact join the financial report and the
// restock velocity service both already use — so this card can't disagree
// with either about what counts as delivered. "Placed" and "cancelled" are
// plain Order counts with no revenue concept, so there's no parallel
// definition being invented for those.
//
// Cancel rate, not the placed-vs-delivered gap, drives urgency: most orders
// placed today won't be delivered today regardless of how healthy the store
// is (delivery takes days), so a nonzero gap is the normal case, not a
// signal — treating it as one would make this card alarm every single day,
// which fails the "not the same reassuring number every day" bar in the
// other direction. The gap is still shown, as context, in the headline.
class SalesPulseCardProvider implements ReportCardProvider
{
    private const CANCEL_RATE_URGENT = 20.0;
    private const CANCEL_RATE_ATTENTION = 10.0;

    public function summarize(int $vendorId): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $today = CarbonImmutable::now();
        $todayStart = $today->startOfDay();
        $todayEnd = $today->endOfDay();

        $placedToday = Order::whereHas('items', fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $cancelledToday = Order::whereHas('items', fn ($q) => $q->where('vendor_id', $vendorId))
            ->where('status', 'cancelled')
            ->whereBetween('updated_at', [$todayStart, $todayEnd])
            ->count();

        $deliveredRevenue = (float) FinancialReportService::recognizedOrderItemsQuery($vendorId, $todayStart, $todayEnd)
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as revenue')
            ->value('revenue');

        $deliveredCount = (int) FinancialReportService::recognizedOrderItemsQuery($vendorId, $todayStart, $todayEnd)
            ->distinct('order_items.order_id')
            ->count('order_items.order_id');

        $cancelRate = $placedToday > 0 ? ($cancelledToday / $placedToday) * 100 : 0.0;
        $gap = max(0, $placedToday - $deliveredCount);

        $headline = "{$placedToday} placed, {$deliveredCount} delivered today"
            . ($gap > 0 ? " ({$gap} awaiting delivery)" : '')
            . ' · ₦' . number_format($deliveredRevenue, 2)
            . ' · ' . number_format($cancelRate, 1) . '% cancelled';

        return new CardSummary(
            key: 'sales_pulse',
            title: 'Sales Pulse',
            headline: $headline,
            actionableCount: $cancelledToday,
            urgency: match (true) {
                $cancelRate >= self::CANCEL_RATE_URGENT => CardSummary::URGENCY_URGENT,
                $cancelRate >= self::CANCEL_RATE_ATTENTION => CardSummary::URGENCY_ATTENTION,
                default => CardSummary::URGENCY_CALM,
            },
            link: SalesReport::getUrl(panel: 'vendor', tenant: $vendor),
        );
    }
}
