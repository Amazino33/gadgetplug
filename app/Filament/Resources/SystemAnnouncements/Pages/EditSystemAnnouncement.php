<?php

namespace App\Filament\Resources\SystemAnnouncements\Pages;

use App\Filament\Resources\SystemAnnouncements\SystemAnnouncementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSystemAnnouncement extends EditRecord
{
    protected static string $resource = SystemAnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
