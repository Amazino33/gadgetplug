<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ProductForm::stripTransientAiFields($data);
        $data['vendor_id'] = filament()->getTenant()->id;

        // The home-store field is only shown to the owner. For anyone else the
        // product is homed in the branch they are working in — never left
        // without one, since ProductObserver opens its stock row there.
        $data['store_id'] ??= \App\Services\ActiveStore::currentId();

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            parent::getCreateFormAction()->label('Save'),
            parent::getCreateAnotherFormAction()->label('Save & Add Another'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
