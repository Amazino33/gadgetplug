<?php

namespace App\Filament\Vendor\Resources\Procurements\Pages;

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListProcurements extends ListRecords
{
    protected static string $resource = ProcurementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_procurement')
                ->label('+ New Procurement')
                ->icon('heroicon-o-plus')
                ->url(route('procurement.create'))
                ->color('warning'),
        ];
    }
}
