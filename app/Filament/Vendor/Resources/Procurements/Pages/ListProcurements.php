<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProcurements extends ListRecords
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Previously a custom button hard-linked to the now-removed
            // ProcurementWizardController route — this was the only path
            // vendors actually clicked, and it never reached the Filament
            // create page. CreateAction() routes to the resource's real
            // 'create' page and respects canCreate() automatically.
            CreateAction::make()
                ->label('New Procurement')
                ->icon('heroicon-o-plus')
                ->color('warning'),
        ];
    }
}
