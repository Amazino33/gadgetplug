<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Pages;

use App\Filament\Resources\Tips\Pages\Concerns\NormalisesTipButton;
use App\Filament\Resources\Tips\TipResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTip extends CreateRecord
{
    use NormalisesTipButton;

    protected static string $resource = TipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$this->normaliseButton($data), 'created_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        $this->makeGifPoster();
    }

    protected function getRedirectUrl(): string
    {
        return TipResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
