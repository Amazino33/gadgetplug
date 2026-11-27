<?php

namespace App\Filament\Vendor\Resources\Aisles\Pages;

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Filament\Vendor\Resources\MappingTasks\MappingTaskResource;
use App\Services\ActiveStore;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAisles extends ListRecords
{
    protected static string $resource = AisleResource::class;

    protected ?string $subheading = 'The places in this branch where goods sit, in the order you walk them. Drag to reorder.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New aisle')
                ->mutateDataUsing(function (array $data): array {
                    $data['vendor_id']  = filament()->getTenant()->id;
                    $data['store_id']   = ActiveStore::currentId();
                    $data['sort_order'] = (int) AisleResource::getEloquentQuery()->max('sort_order') + 1;

                    return $data;
                }),

            Action::make('mapping_tasks')
                ->label('Mapping tasks')
                ->color('gray')
                ->icon('heroicon-o-clipboard-document-list')
                ->url(fn () => MappingTaskResource::getUrl('index')),

            // Mapping is done walking the aisle with a phone.
            Action::make('phone')
                ->label('Map on a phone')
                ->color('gray')
                ->icon('heroicon-o-device-phone-mobile')
                ->url(fn () => route('stock-take.index', ['vendor' => filament()->getTenant()->slug]))
                ->openUrlInNewTab(),
        ];
    }
}
