<?php

namespace App\Filament\Vendor\Resources\StockTakes\Widgets;

use App\Models\Product;
use App\Models\StockTake;
use App\Services\StockTake\StockTakeScope;
use Filament\Tables\Columns\TextColumn;
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
            ->query(fn () => Product::query()->whereIn('products.id', StockTakeScope::uncounted($stockTake)->all() ?: [0]))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Everything in scope has been counted')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('sku')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('aisles_list')
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
