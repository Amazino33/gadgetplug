<?php

namespace App\Filament\Vendor\Resources\Suppliers\Pages;

use App\Filament\Vendor\Pages\HelpCenter;
use App\Filament\Vendor\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Suppliers is where a vendor lands when a procurement cannot find
            // the person they bought from, so the guide that answers "why do I
            // need this at all" belongs on this page.
            HelpCenter::helpAction('how-do-i-add-a-supplier'),
        ];
    }
}
