<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tips\Pages;

use App\Filament\Resources\Tips\Pages\Concerns\NormalisesTipButton;
use App\Filament\Resources\Tips\Tables\TipsTable;
use App\Filament\Resources\Tips\TipResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTip extends EditRecord
{
    use NormalisesTipButton;

    protected static string $resource = TipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TipsTable::previewAction(),
            TipsTable::toggleStatusAction(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normaliseButton($data);
    }

    protected function afterSave(): void
    {
        $this->makeGifPoster();
    }
}
