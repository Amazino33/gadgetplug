<?php

namespace App\Filament\Vendor\Resources\StockTakes\Pages;

use App\Filament\Vendor\Resources\StockTakes\StockTakeResource;
use App\Models\Store;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakePlanner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ListStockTakes extends ListRecords
{
    protected static string $resource = StockTakeResource::class;

    protected ?string $subheading = 'Count this branch aisle by aisle over several days, while it keeps selling.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('New stock take')
                ->icon('heroicon-o-plus')
                ->modalHeading('New stock take')
                ->modalDescription('One stock take at a time per branch. It starts as a draft and becomes active when you assign the first task.')
                ->schema(StockTakeResource::openingFields())
                ->action(function (array $data) {
                    try {
                        $stockTake = app(StockTakePlanner::class)->open(
                            auth()->user(),
                            Store::findOrFail(ActiveStore::currentId()),
                            $data,
                        );
                    } catch (ValidationException|AuthorizationException $e) {
                        Notification::make()
                            ->title('Not done')
                            ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->redirect(StockTakeResource::getUrl('view', ['record' => $stockTake]));
                }),
        ];
    }
}
