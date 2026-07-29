<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

// Replaces the hardcoded sparkline that used to sit under "Today's Revenue" —
// that was a fixed array of made-up numbers, not data.
class RevenueTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return 'Revenue & profit · '.ReportPeriod::fromFilters($this->pageFilters)->label;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $vendorId = Filament::getTenant()?->id;

        if (! $vendorId) {
            return ['datasets' => [], 'labels' => []];
        }

        $period = ReportPeriod::fromFilters($this->pageFilters);

        $series = app(SalesReportService::class)
            ->dailySeries($vendorId, $period->from, $period->to);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue (₦)',
                    'data' => $series['revenue'],
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Profit (₦)',
                    'data' => $series['profit'],
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_inventory_reports')
        );
    }
}
