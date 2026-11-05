<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\Pages\Concerns\ConfiguresProductPage;
use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Services\ActiveStore;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ConfiguresProductPage;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $manual = (bool) ($data['sku_manual'] ?? false) || ProductForm::skuIsManual();

        $data = ProductForm::stripTransientFields($data);
        $data['vendor_id'] = filament()->getTenant()->id;

        // The SKU on screen while typing is only a preview. Unless the vendor
        // chose to write their own, it is dropped here and Product::saving()
        // generates the real one — so two products created at once never share
        // a preview.
        if (! $manual) {
            unset($data['sku']);
        }

        // The home-store field is only shown to the owner. For anyone else the
        // product is homed in the branch they are working in — never left
        // without one, since ProductObserver opens its stock row there.
        $data['store_id'] ??= ActiveStore::currentId();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncDefaultUnit();
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
