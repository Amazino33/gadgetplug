<?php

namespace App\Filament\Vendor\Resources\Stores\Pages;

use App\Filament\Vendor\Resources\Stores\StoreResource;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    // No DeleteAction: stock rows, order allocations, ledger entries and count
    // sessions all reference a store, so closing a branch is deactivation, not
    // deletion. StorePolicy::delete() refuses it outright as well.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
