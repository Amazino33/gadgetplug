<?php

namespace App\Filament\Vendor\Widgets;

use App\Models\OrderItem;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

// All-time figures, deliberately NOT filtered by the dashboard's date range —
// StoreMetricsOverview above already answers "how did the selected period go".
// This one answers "how has the store done overall", which is why it doesn't
// use InteractsWithPageFilters.
class EarningsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $vendor = filament()->getTenant();

        if (! $vendor) {
            return [];
        }

        // Same service as every other widget, so "all time" cannot mean
        // something different here than the period figures do above. Previously
        // this counted online orders only and ignored POS entirely.
        $all = app(SalesReportService::class)->summary(
            $vendor->id,
            CarbonImmutable::create(2000, 1, 1),
            CarbonImmutable::now()->addDay(),
        );

        // Money that is promised but not yet earned, so it is intentionally kept
        // out of revenue above. Online-only by nature: a POS sale is settled at
        // the counter, there is nothing left to clear.
        $pendingRevenue = (float) OrderItem::where('vendor_id', $vendor->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['confirmed', 'shipped']))
            ->sum(DB::raw('quantity * unit_price'));

        return [
            Stat::make('All-Time Revenue', '₦'.number_format($all['revenue'], 2))
                ->description('Online and POS combined')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('All-Time Profit', '₦'.number_format($all['profit'], 2))
                ->description(number_format($all['margin'], 1).'% margin'
                    .($all['cost_is_estimated'] ? ' · partly estimated' : ''))
                ->descriptionIcon('heroicon-m-sparkles')
                ->color($all['profit'] >= 0 ? 'success' : 'danger'),

            Stat::make('Pending Clearance', '₦'.number_format($pendingRevenue, 2))
                ->description('Confirmed or in transit, not yet paid out')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Total Sales', number_format($all['orders']))
                ->description($all['units'].' items sold all time')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),
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
