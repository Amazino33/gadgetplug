<?php

namespace App\Filament\Resources\SupplierLinks\Pages;

use App\Filament\Resources\SupplierLinks\SupplierLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierLinks extends ListRecords
{
    protected static string $resource = SupplierLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
