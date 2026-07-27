<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['images'] = $this->record
            ->getMedia('product-images')
            ->pluck('uuid')
            ->toArray();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = ProductForm::stripTransientAiFields($data);

        // A human manually changing the selling price on this form is what
        // "overridden" means — from here on, the procurement reconciliation
        // engine leaves this product's price alone and only updates
        // cost_price, surfacing the suggestion as a delta instead.
        if (array_key_exists('price', $data) && (float) $data['price'] !== (float) $this->record->price) {
            $data['price_overridden'] = true;
        }

        return $data;
    }
}
