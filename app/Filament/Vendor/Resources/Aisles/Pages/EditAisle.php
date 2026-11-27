<?php

namespace App\Filament\Vendor\Resources\Aisles\Pages;

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use Filament\Resources\Pages\EditRecord;

class EditAisle extends EditRecord
{
    protected static string $resource = AisleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Someone who may only map sees the products, not a form they cannot save. */
    protected function getFormActions(): array
    {
        return AisleResource::canManage() ? parent::getFormActions() : [];
    }

    protected function getRedirectUrl(): ?string
    {
        return AisleResource::getUrl('index');
    }
}
