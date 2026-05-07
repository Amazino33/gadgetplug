<?php

namespace App\Filament\Vendor\Resources\AuditSessionResource\Pages;

use App\Filament\Vendor\Resources\AuditSessionResource;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAuditSessions extends ManageRecords
{
    protected static string $resource = AuditSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
