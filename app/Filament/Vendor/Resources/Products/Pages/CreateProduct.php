<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    // This form is two real columns of content, not a narrow single-column
    // one. At the panel default it was rendering at roughly half the space
    // available, which wrapped the selects onto two lines and pushed the
    // page well past one screen. Full width is what makes the pairings in
    // ProductForm actually sit side by side.
    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }

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
