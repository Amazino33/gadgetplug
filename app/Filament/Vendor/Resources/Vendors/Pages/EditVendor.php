<?php

namespace App\Filament\Vendor\Resources\Vendors\Pages;

use App\Filament\Vendor\Resources\Vendors\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
