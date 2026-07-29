<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\Product;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\SalesReportService;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StoreMetricsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $vendorId = Filament::getTenant()?->id;

        if (! $vendorId) {
            return [];
        }

        $reports = app(SalesReportService::class);
        $period = ReportPeriod::fromFilters($this->pageFilters);
        $previous = $period->previous();

        $current = $reports->summary($vendorId, $period->from, $period->to);
        $before = $reports->summary($vendorId, $previous->from, $previous->to);

        $stockValue = (float) Product::where('vendor_id', $vendorId)
            ->sum(DB::raw('stock_quantity * price'));

        return [
            Stat::make('Revenue', '₦'.number_format($current['revenue'], 2))
                ->description($this->comparison($current['revenue'], $before['revenue']))
                ->descriptionIcon($current['revenue'] >= $before['revenue']
                    ? 'heroicon-m-arrow-trending-up'
                    : 'heroicon-m-arrow-trending-down')
                ->color($current['revenue'] >= $before['revenue'] ? 'success' : 'danger'),

            Stat::make('Profit', '₦'.number_format($current['profit'], 2))
                ->description($current['cost_is_estimated']
                    // Said plainly rather than hidden: some of these lines sold
                    // before cost was recorded per sale, so their profit leans
                    // on the product's cost price today.
                    ? number_format($current['margin'], 1).'% margin · partly estimated'
                    : number_format($current['margin'], 1).'% margin')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($current['profit'] >= 0 ? 'success' : 'danger'),

            Stat::make('Sales', number_format($current['orders']))
                ->description($current['units'].' items sold · '.$period->label)
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),

            Stat::make('Stock on Hand', '₦'.number_format($stockValue, 2))
                ->description('Retail value of current inventory')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary'),
        ];
    }

    private function comparison(float $current, float $previous): string
    {
        if ($previous <= 0.0) {
            return $current > 0.0 ? 'No sales in the previous period' : 'No sales yet';
        }

        $change = (($current - $previous) / $previous) * 100;

        return ($change >= 0 ? '+' : '').number_format($change, 1).'% vs previous period';
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
