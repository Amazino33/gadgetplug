<?php

namespace App\Filament\Vendor\Resources\PosSales\Pages;

use App\Filament\Vendor\Resources\PosSales\PosSaleResource;
use Filament\Resources\Pages\ListRecords;

class ListPosSales extends ListRecords
{
    protected static string $resource = PosSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
