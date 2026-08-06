<?php

namespace App\Filament\Resources\AffiliateLevels\Pages;

use App\Filament\Resources\AffiliateLevels\AffiliateLevelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAffiliateLevel extends EditRecord
{
    protected static string $resource = AffiliateLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
