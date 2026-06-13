<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\Product;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Filament\Facades\Filament;

class StoreMetricsOverview extends BaseWidget
{
    protected static ?int $sort = 1;
    protected null|string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $vendorId = Filament::getTenant()?->id;

        // 1. Total Retail Value (What customers will pay)
        $totalStockValue = (float) Product::where('vendor_id', $vendorId)
            ->sum(DB::raw('stock_quantity * price'));

        // 2. Total Cost Value (What the vendor paid to buy it all)
        $totalStockCost = (float) Product::where('vendor_id', $vendorId)
            ->sum(DB::raw('stock_quantity * cost_price'));

        // 3. The Magic Number: Potential Profit
        $potentialProfit = $totalStockValue - $totalStockCost;

        // 4. Today's Revenue (Unchanged from before)
        $todayOrders = Order::where('status', 'paid')
            ->whereDate('created_at', Carbon::today());

        $dailyRevenue = (float) $todayOrders->sum('total_amount');
        $dailyVolume = (int) $todayOrders->count();

        return [
            Stat::make('Total Stock Value', '₦' . number_format($totalStockValue, 2))
                ->description('Retail value of current inventory')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            // NEW STAT: The Profit Dashboard Card
            Stat::make('Potential Profit', '₦' . number_format($potentialProfit, 2))
                ->description('Expected profit if all stock is sold')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('success'),

            Stat::make('Today\'s Revenue', '₦' . number_format($dailyRevenue, 2))
                ->description((string) $dailyVolume . ' orders completed today')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info')
                ->chart([7, 2, 10, 3, 15, 4, 17]), 
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