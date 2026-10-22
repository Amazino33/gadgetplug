<?php

namespace App\Filament\Pages\Delivery;

use App\Filament\Pages\DeliverySettings;
use App\Models\Store;
use App\Services\Delivery\NigeriaBounds;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Store locations on Delivery settings. A store with a pin is a delivery hub:
 * distances to every LGA are measured from the nearest one.
 */
class HubsTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    // Rendered with the page, not when scrolled to: a blank card where a table
    // belongs reads as broken.
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Store locations (hubs)';

    public static function canView(): bool
    {
        return DeliverySettings::canAccess();
    }

    public function boot(): void
    {
        DeliveryRuleForm::authorize();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Store::query()->where('is_active', true)->with('vendor:id,name'))
            ->description('A pinned store is a delivery hub. Distances are measured, straight-line, from the nearest one.')
            ->groups([Group::make('vendor.name')->label('Vendor')->titlePrefixedWithLabel(false)->collapsible()])
            ->defaultGroup('vendor.name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Store')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Store $store): string => self::isPinned($store)
                        ? number_format((float) $store->latitude, 5).', '.number_format((float) $store->longitude, 5)
                        : 'Not pinned yet'),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('pin_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Store $store): string => self::isPinned($store) ? 'Pinned' : 'Not pinned')
                    ->color(fn (string $state): string => $state === 'Pinned' ? 'success' : 'gray')
                    ->icon(fn (string $state): string => $state === 'Pinned' ? 'heroicon-m-map-pin' : 'heroicon-m-minus-circle')
                    // On a phone the line under the store name says it.
                    ->visibleFrom('sm'),
            ])
            ->filters([
                TernaryFilter::make('pinned')
                    ->label('Location')
                    ->trueLabel('Pinned')
                    ->falseLabel('Not pinned')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('latitude')->whereNotNull('longitude'),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude')),
                    ),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->pinOnMapAction(),
                    $this->editCoordinatesAction(),
                    $this->removePinAction(),
                ])
                    ->label('Location')
                    ->icon('heroicon-m-map-pin')
                    ->button()
                    ->color('gray'),
            ])
            ->emptyStateHeading('No active stores')
            ->emptyStateDescription('Stores appear here once a vendor has one.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    private function pinOnMapAction(): Action
    {
        return Action::make('pinOnMap')
            ->label('Pin on map')
            ->icon('heroicon-m-map')
            ->modalHeading(fn (Store $record): string => 'Pin '.$record->name)
            ->modalDescription('Tap the map to drop the pin, or drag it. Standing at the store? Use your current location.')
            ->modalSubmitActionLabel('Save location')
            ->extraModalWindowAttributes(['class' => 'gp-phone-sheet'])
            ->stickyModalFooter()
            ->fillForm(fn (Store $record): array => self::coordinates($record))
            ->schema([
                ViewField::make('pin_map')
                    ->hiddenLabel()
                    ->view('filament.admin.delivery.pin-map')
                    ->dehydrated(false),
                ...self::coordinateFields(),
            ])
            ->action(fn (Store $record, array $data) => $this->savePin($record, $data));
    }

    private function editCoordinatesAction(): Action
    {
        return Action::make('editCoordinates')
            ->label('Edit coordinates')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(fn (Store $record): string => 'Coordinates — '.$record->name)
            ->modalWidth('md')
            ->modalSubmitActionLabel('Save location')
            ->fillForm(fn (Store $record): array => self::coordinates($record))
            ->schema(self::coordinateFields())
            ->action(fn (Store $record, array $data) => $this->savePin($record, $data));
    }

    private function removePinAction(): Action
    {
        return Action::make('removePin')
            ->label('Remove pin')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->visible(fn (Store $record): bool => self::isPinned($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Store $record): string => 'Remove '.$record->name.' as a hub?')
            ->modalDescription('Distances will be measured from the other pinned stores. Prices already set do not change.')
            ->action(function (Store $record): void {
                DeliveryRuleForm::authorize();
                $record->update(['latitude' => null, 'longitude' => null]);

                $this->changed('Pin removed from '.$record->name.'.');
            });
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    private static function coordinateFields(): array
    {
        return [
            Grid::make(['default' => 2])->schema([
                TextInput::make('latitude')
                    ->label('Latitude')
                    ->numeric()
                    ->required()
                    ->minValue(NigeriaBounds::MIN_LAT)
                    ->maxValue(NigeriaBounds::MAX_LAT)
                    ->step('any')
                    ->validationMessages([
                        'min' => 'That is south of Nigeria — check the number.',
                        'max' => 'That is north of Nigeria — check the number.',
                    ]),
                TextInput::make('longitude')
                    ->label('Longitude')
                    ->numeric()
                    ->required()
                    ->minValue(NigeriaBounds::MIN_LNG)
                    ->maxValue(NigeriaBounds::MAX_LNG)
                    ->step('any')
                    ->validationMessages([
                        'min' => 'That is west of Nigeria — check the number.',
                        'max' => 'That is east of Nigeria — check the number.',
                    ]),
            ]),
        ];
    }

    private function savePin(Store $store, array $data): void
    {
        DeliveryRuleForm::authorize();

        $store->update([
            'latitude'  => round((float) $data['latitude'], 6),
            'longitude' => round((float) $data['longitude'], 6),
        ]);

        $this->changed($store->name.' pinned.');
    }

    private function changed(string $message): void
    {
        Notification::make()->title($message)->success()->send();

        // The stats, the map and the rules all depend on where the hubs are.
        $this->dispatch('delivery-rules-changed');
    }

    private static function isPinned(Store $store): bool
    {
        return $store->latitude !== null && $store->longitude !== null;
    }

    /** @return array{latitude: ?float, longitude: ?float} */
    private static function coordinates(Store $store): array
    {
        return [
            'latitude'  => $store->latitude !== null ? (float) $store->latitude : null,
            'longitude' => $store->longitude !== null ? (float) $store->longitude : null,
        ];
    }
}
