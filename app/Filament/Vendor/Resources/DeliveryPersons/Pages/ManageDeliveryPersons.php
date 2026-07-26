<?php

namespace App\Filament\Vendor\Resources\DeliveryPersons\Pages;

use App\Filament\Vendor\Resources\DeliveryPersons\DeliveryPersonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeliveryPersons extends ManageRecords
{
    protected static string $resource = DeliveryPersonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
