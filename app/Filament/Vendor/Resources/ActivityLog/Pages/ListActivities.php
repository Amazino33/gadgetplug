<?php

namespace App\Filament\Vendor\Resources\ActivityLog\Pages;

use App\Filament\Vendor\Resources\ActivityLog\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
