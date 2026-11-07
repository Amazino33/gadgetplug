<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

use App\Filament\Vendor\Pages\PaymentMethodsReport;
use App\Models\Vendor;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;

// Today's shop takings by how customers paid, read from
// SalesReportService::paymentBreakdown() — the same figures as the Sales and
// Payment Methods reports, so the card can't disagree with either.
//
// Urgency follows the share of today's sales that left on credit, not the
// takings themselves: a quiet day is not a problem, but goods walking out
// unpaid is money the shop still has to chase.
class PaymentMethodsCardProvider implements ReportCardProvider
{
    private const CREDIT_SHARE_URGENT = 50.0;

    private const CREDIT_SHARE_ATTENTION = 25.0;

    private const LABELS = ['cash' => 'Cash', 'bank_transfer' => 'Transfer', 'card' => 'Card'];

    public function __construct(private readonly SalesReportService $reports) {}

    public function summarize(int $vendorId, ?int $storeId = null): CardSummary
    {
        $vendor = Vendor::findOrFail($vendorId);
        $today = ReportPeriod::fromFilters(['preset' => 'today']);
        $yesterday = ReportPeriod::fromFilters(['preset' => 'yesterday']);

        $sales = $this->reports->paymentBreakdown($vendorId, $today->from, $today->to, $storeId)['sales'];
        $before = $this->reports->paymentBreakdown($vendorId, $yesterday->from, $yesterday->to, $storeId)['sales'];

        $received = $sales['cash'] + $sales['card'] + $sales['bank_transfer'];
        $receivedBefore = $before['cash'] + $before['card'] + $before['bank_transfer'];
        $credit = $sales['debt'];
        $creditShare = ($received + $credit) > 0 ? ($credit / ($received + $credit)) * 100 : 0.0;

        $link = PaymentMethodsReport::getUrl(panel: 'vendor', tenant: $vendor);

        if ($received + $credit <= 0) {
            return new CardSummary(
                key: 'payment_methods',
                title: 'Payment Methods',
                headline: 'No shop sales yet today',
                comparison: '₦'.number_format($receivedBefore, 2).' received yesterday',
                comparisonDirection: 'flat',
                link: $link,
            );
        }

        // Largest tender first, and only the ones actually used today.
        $split = collect(self::LABELS)
            ->map(fn (string $label, string $method) => ['label' => $label, 'amount' => $sales[$method]])
            ->filter(fn (array $tender) => $tender['amount'] > 0)
            ->sortByDesc('amount')
            ->map(fn (array $tender) => $tender['label'].' ₦'.number_format($tender['amount'], 2))
            ->implode(' · ');

        return new CardSummary(
            key: 'payment_methods',
            title: 'Payment Methods',
            headline: '₦'.number_format($received, 2).' received today'.($split !== '' ? " — {$split}" : ''),
            comparison: 'vs ₦'.number_format($receivedBefore, 2).' yesterday',
            comparisonDirection: match (true) {
                $received > $receivedBefore => 'up',
                $received < $receivedBefore => 'down',
                default => 'flat',
            },
            urgency: match (true) {
                $creditShare >= self::CREDIT_SHARE_URGENT => CardSummary::URGENCY_URGENT,
                $creditShare >= self::CREDIT_SHARE_ATTENTION => CardSummary::URGENCY_ATTENTION,
                default => CardSummary::URGENCY_CALM,
            },
            link: $link,
            note: $credit > 0
                ? '₦'.number_format($credit, 2).' sold on credit ('.number_format($creditShare, 0).'% of sales), not yet received'
                : null,
        );
    }
}
