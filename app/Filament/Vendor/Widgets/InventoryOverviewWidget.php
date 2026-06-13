<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Widgets;

use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class InventoryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected null|string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $vendorId = Filament::getTenant()?->id;

        $base = Product::where('vendor_id', $vendorId);

        $totalSKUs   = (clone $base)->count();
        $totalUnits  = (int)   (clone $base)->sum('stock_quantity');
        $retailValue = (float) (clone $base)->sum(DB::raw('stock_quantity * price'));
        $costValue   = (float) (clone $base)->sum(DB::raw('stock_quantity * cost_price'));
        $profit      = $retailValue - $costValue;
        $margin      = $retailValue > 0 ? ($profit / $retailValue) * 100 : 0.0;
        $lowStock    = (clone $base)->where('stock_quantity', '>', 0)->where('stock_quantity', '<', 5)->count();
        $outOfStock  = (clone $base)->where('stock_quantity', 0)->count();

        return [
            Stat::make('Total SKUs', number_format($totalSKUs))
                ->description('Unique products in your catalogue')
                ->descriptionIcon('heroicon-m-squares-2x2')
                ->color('primary'),

            Stat::make('Units in Stock', number_format($totalUnits))
                ->description('Total individual items currently held')
                ->descriptionIcon('heroicon-m-cube-transparent')
                ->color('info'),

            Stat::make('Stock Retail Value', '₦' . number_format($retailValue))
                ->description('Revenue if all stock sells today')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Stock Cost Value', '₦' . number_format($costValue))
                ->description('Capital tied up in current stock')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('warning'),

            Stat::make('Gross Profit Potential', '₦' . number_format($profit))
                ->description(number_format($margin, 1) . '% average gross margin on stock')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($profit >= 0 ? 'success' : 'danger'),

            Stat::make('Stock Alerts', $outOfStock . ' out of stock · ' . $lowStock . ' low')
                ->description(
                    $outOfStock > 0
                        ? 'Restocking required on ' . $outOfStock . ' item(s)'
                        : ($lowStock > 0 ? 'Running low on ' . $lowStock . ' item(s)' : 'All products well-stocked')
                )
                ->descriptionIcon($outOfStock > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($outOfStock > 0 ? 'danger' : ($lowStock > 0 ? 'warning' : 'success')),
        ];
    }

    public static function canView(): bool 
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorRole($vendor->id, ['inventory_manager', 'storekeeper', 'store_admin'])
        );
    }
}
