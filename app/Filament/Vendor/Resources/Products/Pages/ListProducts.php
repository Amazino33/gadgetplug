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
        return [
            Action::make('tableView')
                ->label('Table')
                ->icon('heroicon-o-table-cells')
                ->color(fn (): string => $this->displayMode === 'table' ? 'primary' : 'gray')
                ->action(fn () => $this->displayMode = 'table'),

            Action::make('gridView')
                ->label('Grid')
                ->icon('heroicon-o-squares-2x2')
                ->color(fn (): string => $this->displayMode === 'grid' ? 'primary' : 'gray')
                ->action(fn () => $this->displayMode = 'grid'),

            CreateAction::make(),
        ];
    }
}
