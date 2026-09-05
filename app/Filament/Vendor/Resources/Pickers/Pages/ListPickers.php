<?php

namespace App\Filament\Vendor\Resources\Pickers\Pages;

use App\Actions\Pickings\ReleaseToPickerAction;
use App\Filament\Vendor\Resources\Pickers\PickerResource;
use App\Models\Picker;
use App\Models\Product;
use App\Services\ActiveStore;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class ListPickers extends ListRecords
{
    protected static string $resource = PickerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->releaseAction(),
            CreateAction::make()
                ->label('Add picker')
                ->icon('heroicon-o-user-plus')
                ->color('gray'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Picker')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Picker $record) => $record->phone),

                TextColumn::make('shop')
                    ->label('Shop')
                    ->placeholder('—')
                    ->toggleable()
                    ->wrap(),

                TextColumn::make('units_held')
                    ->label('Units held')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('value_out')
                    ->label('Worth today')
                    ->money('NGN')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray')
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Trading')
                    ->boolean()
                    ->toggleable(),
            ])
            // Most of the vendor's money first: this list is read from the top.
            ->defaultSort('value_out', 'desc')
            ->recordActions([
                ViewAction::make()->label('Open'),
            ])
            ->emptyStateHeading('Nobody is holding your goods')
            ->emptyStateDescription('Add a picker, then release goods to them.');
    }

    /**
     * Hand goods out.
     *
     * The product list is scoped to the branch the goods are leaving, because a
     * product lives in exactly one branch — offering the whole catalogue would
     * mean picking things that branch cannot hand over.
     */
    private function releaseAction(): Action
    {
        $vendor = filament()->getTenant();
        $stores = ActiveStore::accessibleFor($vendor, auth()->user());

        return Action::make('release')
            ->label('Release goods')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading('Release goods to a picker')
            ->modalDescription('The units leave the shelf now. They are still yours until paid for, and can be asked back.')
            ->modalSubmitActionLabel('Release')
            ->schema([
                Select::make('picker_id')
                    ->label('Picker')
                    ->options(fn () => Picker::forVendor($vendor->id)->active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('store_id')
                    ->label('From branch')
                    ->options($stores->pluck('name', 'id'))
                    ->default($stores->firstWhere('is_default', true)?->id ?? $stores->first()?->id)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required()
                    ->visible($stores->count() > 1),

                Repeater::make('items')
                    ->label('Products')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(function (Get $get) use ($vendor, $stores) {
                                $storeId = $get('../../store_id')
                                    ?? $stores->firstWhere('is_default', true)?->id
                                    ?? $stores->first()?->id;

                                return Product::where('vendor_id', $vendor->id)
                                    ->where('store_id', $storeId)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->required(),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add another product'),
            ])
            ->action(function (array $data) use ($vendor, $stores): void {
                $picker = Picker::forVendor($vendor->id)->findOrFail($data['picker_id']);
                $storeId = $data['store_id']
                    ?? $stores->firstWhere('is_default', true)?->id
                    ?? $stores->first()?->id;

                try {
                    $picking = app(ReleaseToPickerAction::class)->execute(
                        picker: $picker,
                        store: (int) $storeId,
                        lines: array_map(fn ($row) => [
                            'product_id' => (int) $row['product_id'],
                            'quantity'   => (int) $row['quantity'],
                        ], $data['items']),
                        userId: auth()->id(),
                    );

                    Notification::make()
                        ->title("Released to {$picker->name}")
                        ->body("{$picking->reference} — the units are off the shelf and out on trust.")
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    // Nothing was recorded: the release is one transaction, so a
                    // line the branch cannot cover takes the whole trip with it.
                    Notification::make()
                        ->title('Nothing was released')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
