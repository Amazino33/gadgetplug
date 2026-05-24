<?php

namespace App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource\Pages;

use App\Filament\Vendor\Resources\AuditSessions\AuditSessionResource;

use Filament\Resources\Pages\ManageRecords;

class ManageAuditSessions extends ManageRecords
{
    protected static string $resource = AuditSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
