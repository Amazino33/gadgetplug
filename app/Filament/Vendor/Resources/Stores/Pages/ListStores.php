<?php

namespace App\Filament\Vendor\Resources\Stores\Pages;

use App\Filament\Vendor\Resources\Stores\StoreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStores extends ListRecords
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Open a branch'),
        ];
    }
}
