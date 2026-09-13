<?php

namespace App\Filament\Resources\SystemAnnouncements\Pages;

use App\Filament\Resources\SystemAnnouncements\SystemAnnouncementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSystemAnnouncement extends CreateRecord
{
    protected static string $resource = SystemAnnouncementResource::class;
}
