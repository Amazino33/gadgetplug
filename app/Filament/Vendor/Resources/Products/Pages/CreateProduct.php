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
