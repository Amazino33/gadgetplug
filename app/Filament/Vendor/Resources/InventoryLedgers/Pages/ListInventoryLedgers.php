<?php

namespace App\Filament\Vendor\Resources\InventoryLedgers\Pages;

use App\Filament\Vendor\Resources\InventoryLedgers\InventoryLedgerResource;
use Filament\Resources\Pages\ListRecords;

class ListInventoryLedgers extends ListRecords
{
    protected static string $resource = InventoryLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
