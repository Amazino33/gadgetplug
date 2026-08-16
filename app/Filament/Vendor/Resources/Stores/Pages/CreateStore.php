<?php

namespace App\Filament\Vendor\Resources\Stores\Pages;

use App\Filament\Vendor\Resources\Stores\StoreResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStore extends CreateRecord
{
    protected static string $resource = StoreResource::class;

    // A new branch opens for trade but is not the fallback, and holds nothing
    // until stock is moved into it. is_default is never set here: promoting a
    // branch is its own guarded action, because doing it while stock is
    // reserved at the current main store can strand that reservation.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // vendor_id is set here rather than by Filament's tenancy: this
        // resource deliberately does not declare tenant ownership (see
        // StoreResource), so nothing else would fill it.
        $data['vendor_id']  = filament()->getTenant()->id;
        $data['is_active']  = true;
        $data['is_default'] = false;

        return $data;
    }
}
