<?php

namespace App\Filament\Vendor\Resources\MappingTasks\Pages;

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Filament\Vendor\Resources\MappingTasks\MappingTaskResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListMappingTasks extends ListRecords
{
    protected static string $resource = MappingTaskResource::class;

    protected ?string $subheading = 'Assign one from Aisles: tick the aisles, then Assign mapping task.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('aisles')->label('Back to aisles')->color('gray')->url(fn () => AisleResource::getUrl('index')),
        ];
    }
}
