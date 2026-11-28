<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Support\Visibility;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesChannelChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return 'Where sales came from · '.ReportPeriod::fromFilters($this->pageFilters)->label;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $vendorId = Filament::getTenant()?->id;

        if (! $vendorId) {
            return ['datasets' => [], 'labels' => []];
        }

        $period = ReportPeriod::fromFilters($this->pageFilters);

        // Previously this split channels by orders.payment_method, which could
        // never work: POS sales are not rows in `orders` at all, so the POS
        // slice was permanently zero and the chart rendered empty.
        $breakdown = app(SalesReportService::class)
            ->channelBreakdown($vendorId, $period->from, $period->to);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (₦)',
                    'data' => array_values($breakdown),
                    'backgroundColor' => ['#3b82f6', '#10b981'],
                ],
            ],
            'labels' => array_keys($breakdown),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            ($user->hasVendorPermission($vendor->id, 'view_inventory_reports')
                // Adds up every branch's takings: for those allowed to see
                // sales totals and every branch (Visibility).
                && Visibility::salesTotals($user, $vendor)
                && Visibility::allBranches($vendor, $user))
        );
    }
}
