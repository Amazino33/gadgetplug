<?php

namespace App\Filament\Vendor\Resources\Products\Pages;

use App\Filament\Vendor\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    // Persisted via ?display=grid so a shared/bookmarked link keeps the chosen view.
    #[Url(as: 'display', keep: true)]
    public string $displayMode = 'table';

    protected function getHeaderActions(): array
    {
        // Real navigation links, not Livewire property-setting actions — Filament
        // caches the Table config during the request's boot phase, before an
        // in-place property update would take effect, so a live click never
        // sees the new displayMode in time. A fresh page load (URL already
        // carrying ?display=...) hydrates it correctly from the start.
        return [
            Action::make('tableView')
                ->label('Table')
                ->icon('heroicon-o-table-cells')
                ->color(fn (): string => $this->displayMode === 'table' ? 'primary' : 'gray')
                ->url(fn (): string => static::getUrl(parameters: ['display' => 'table'])),

            Action::make('gridView')
                ->label('Grid')
                ->icon('heroicon-o-squares-2x2')
                ->color(fn (): string => $this->displayMode === 'grid' ? 'primary' : 'gray')
                ->url(fn (): string => static::getUrl(parameters: ['display' => 'grid'])),

            CreateAction::make(),
        ];
    }
}
