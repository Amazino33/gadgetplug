<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ImportLogs\Pages;

use App\Filament\Vendor\Pages\ImportProducts;
use App\Filament\Vendor\Resources\ImportLogs\ImportLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListImportLogs extends ListRecords
{
    protected static string $resource = ImportLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('New import')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn (): string => ImportProducts::getUrl())
                ->visible(fn (): bool => ImportProducts::canAccess()),
        ];
    }
}
