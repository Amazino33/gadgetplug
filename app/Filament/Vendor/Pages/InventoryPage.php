<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Filament\Vendor\Widgets\InventoryTableWidget;
use App\Filament\Vendor\Widgets\StockMovementChart;
use Filament\Pages\Page;
use BackedEnum;

class InventoryPage extends Page
{
    protected static null|string|BackedEnum $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Inventory';
    protected static ?string $title           = 'Inventory & Stock Evaluation';
    protected static ?int    $navigationSort  = 2;
    protected string  $view            = 'filament.vendor.pages.inventory';

    protected function getHeaderWidgets(): array
    {
        return [InventoryOverviewWidget::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            InventoryTableWidget::class,
            StockMovementChart::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'view_any_products')
        );
    }
}
