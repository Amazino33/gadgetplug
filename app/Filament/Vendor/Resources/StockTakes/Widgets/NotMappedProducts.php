<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Models\StockTake;
use App\Services\StockTake\StockTakeScope;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Products with stock at the branch that sit on no active aisle. A count by
 * aisle never reaches them, so they are outside this stock take until mapped.
 */
class NotMappedProducts extends TableWidget
{
    protected static bool $isDiscovered = false;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Not on any aisle (outside the count)';

    #[On('stock-take-updated')]
    public function refreshList(): void {}

    public function table(Table $table): Table
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        return $table
            ->extraAttributes(['class' => 'gp-phone-cards'])
            ->query(fn () => StockTakeScope::notMappedQuery((int) $stockTake->vendor_id, (int) $stockTake->store_id))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Every product with stock is on an aisle')
            ->columns([
                // Phone only: one card per row (mobile-row.blade.php), so nothing
                // runs off the side of the screen. The columns below are md and up.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->state(fn ($record) => [
                        'title' => $record->name,
                        'lines' => [collect([$record->sku, $record->barcode])->filter()->implode(' · ')],
                    ])
                    ->view('filament.vendor.stock-take.mobile-row')
                    ->disabledClick()
                    ->hiddenFrom('md'),
                TextColumn::make('name')->visibleFrom('md')->searchable()->weight('bold'),
                TextColumn::make('sku')->visibleFrom('md')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('barcode')->visibleFrom('md')->placeholder('—')->searchable(),
            ])
            ->headerActions([
                Action::make('aisles')->label('Go to Aisles')->color('gray')->url(fn () => AisleResource::getUrl('index')),
            ]);
    }
}
