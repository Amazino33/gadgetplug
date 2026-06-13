<?php

namespace App\Filament\Vendor\Widgets;

use App\Models\OrderItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class EarningsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $vendor = filament()->getTenant();

        // Revenue only from paid/delivered orders
        $base = OrderItem::where('vendor_id', $vendor->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['paid', 'delivered']));

        $totalRevenue  = (clone $base)->sum(DB::raw('quantity * unit_price'));

        $thisMonth     = (clone $base)
            ->whereHas('order', fn($q) => $q->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year))
            ->sum(DB::raw('quantity * unit_price'));

        $lastMonth     = (clone $base)
            ->whereHas('order', fn($q) => $q->whereMonth('created_at', now()->subMonth()->month)->whereYear('created_at', now()->subMonth()->year))
            ->sum(DB::raw('quantity * unit_price'));

        $pendingRevenue = OrderItem::where('vendor_id', $vendor->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['confirmed', 'shipped']))
            ->sum(DB::raw('quantity * unit_price'));

        $ordersThisMonth = OrderItem::where('vendor_id', $vendor->id)
            ->whereHas('order', fn($q) => $q->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year))
            ->distinct('order_id')
            ->count('order_id');

        $monthChange = $lastMonth > 0
            ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1)
            : ($thisMonth > 0 ? 100 : 0);

        return [
            Stat::make('Total Earnings', '₦' . number_format($totalRevenue))
                ->description('All paid & delivered orders')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('This Month', '₦' . number_format($thisMonth))
                ->description(($monthChange >= 0 ? '+' : '') . $monthChange . '% vs last month')
                ->descriptionIcon($monthChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($monthChange >= 0 ? 'success' : 'danger'),

            Stat::make('Pending Clearance', '₦' . number_format($pendingRevenue))
                ->description('Orders confirmed or in transit')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),

            Stat::make('Orders This Month', $ordersThisMonth)
                ->description('Unique orders received')
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
            $user->hasVendorRole($vendor->id, ['store_admin'])
        );
    }
}
