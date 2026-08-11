<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\StockAccountability\Pages;

use App\Filament\Vendor\Resources\StockAccountability\StockAccountabilityResource;
use App\Filament\Vendor\Widgets\StockLiabilityOverview;
use Filament\Resources\Pages\ListRecords;

class ListStockAccountability extends ListRecords
{
    protected static string $resource = StockAccountabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            StockLiabilityOverview::class,
        ];
    }
}
