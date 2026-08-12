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
}
