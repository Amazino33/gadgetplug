<?php

namespace App\Filament\Resources\SystemAnnouncements\Pages;

use App\Filament\Resources\SystemAnnouncements\SystemAnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSystemAnnouncements extends ListRecords
{
    protected static string $resource = SystemAnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
