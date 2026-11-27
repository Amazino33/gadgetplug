<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\CountSessions\Pages;

use App\Filament\Vendor\Resources\CountSessions\CountSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListCountSessions extends ListRecords
{
    protected static string $resource = CountSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Stock Take's approved tasks, in the same columns, below the counts. */
    protected function getFooterWidgets(): array
    {
        return filament()->getTenant()?->hasStockTake()
            ? [\App\Filament\Vendor\Resources\CountSessions\Widgets\StockTakeAudits::class]
            : [];
    }
}
