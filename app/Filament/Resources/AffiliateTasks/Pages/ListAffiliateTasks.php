<?php

namespace App\Filament\Resources\AffiliateTasks\Pages;

use App\Filament\Resources\AffiliateTasks\AffiliateTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAffiliateTasks extends ListRecords
{
    protected static string $resource = AffiliateTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
