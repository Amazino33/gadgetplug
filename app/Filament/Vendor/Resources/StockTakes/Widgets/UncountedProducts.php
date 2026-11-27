<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\Product;
use App\Models\StockTake;
use App\Services\StockTake\StockTakeScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

/** Products in scope that no handed-in task has counted yet, with where they sit. */
class UncountedProducts extends TableWidget
{
    protected static bool $isDiscovered = false;

    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Not counted yet';

    #[On('stock-take-updated')]
    public function refreshList(): void {}

    public function table(Table $table): Table
    {
        /** @var StockTake $stockTake */
        $stockTake = $this->record;

        return $table
            ->extraAttributes(['class' => 'gp-phone-cards'])
            ->query(fn () => Product::query()->whereIn('products.id', StockTakeScope::uncounted($stockTake)->all() ?: [0]))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Everything in scope has been counted')
            ->columns([
                // Phone only: one card per row (mobile-row.blade.php), so nothing
                // runs off the side of the screen. The columns below are md and up.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->state(fn (Product $record) => [
                        'title' => $record->name,
                        'lines' => [
                            $record->sku,
                            'Aisles: ' . (DB::table('aisle_products')
                                ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
                                ->where('aisle_products.product_id', $record->id)
                                ->where('aisles.store_id', $stockTake->store_id)
                                ->where('aisles.is_active', true)
                                ->orderBy('aisles.sort_order')
                                ->pluck('aisles.name')
                                ->implode(', ') ?: 'none'),
                        ],
                    ])
                    ->view('filament.vendor.stock-take.mobile-row')
                    ->disabledClick()
                    ->hiddenFrom('md'),
                TextColumn::make('name')->visibleFrom('md')->searchable()->weight('bold'),
                TextColumn::make('sku')->visibleFrom('md')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('aisles_list')->visibleFrom('md')
                    ->label('Aisles')
                    ->state(fn (Product $record) => DB::table('aisle_products')
                        ->join('aisles', 'aisles.id', '=', 'aisle_products.aisle_id')
                        ->where('aisle_products.product_id', $record->id)
                        ->where('aisles.store_id', $stockTake->store_id)
                        ->where('aisles.is_active', true)
                        ->orderBy('aisles.sort_order')
                        ->pluck('aisles.name')
                        ->implode(', '))
                    ->placeholder('—'),
            ]);
    }
}
