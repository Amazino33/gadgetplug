<?php

namespace App\Filament\Resources\AffiliateLevels\Pages;

use App\Filament\Resources\AffiliateLevels\AffiliateLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAffiliateLevels extends ListRecords
{
    protected static string $resource = AffiliateLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
