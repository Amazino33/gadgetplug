<?php

namespace App\Filament\Resources\ClientRequests\Pages;

use App\Filament\Resources\ClientRequests\ClientRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientRequests extends ListRecords
{
    protected static string $resource = ClientRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
