<?php

namespace App\Filament\Vendor\Resources\Aisles\RelationManagers;

use App\Models\Aisle;
use App\Models\AisleProduct;
use App\Models\Product;
use App\Services\StockTake\StockTakeAccess;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The aisle's map: products recorded as sitting here. Usually built by
 * scanning on a phone; added and removed here by hand when that is quicker.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products on this aisle';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Aisle;
    }

    private function mayMap(): bool
    {
        /** @var Aisle $aisle */
        $aisle = $this->getOwnerRecord();

        return $aisle->is_active && StockTakeAccess::canMapAisle(auth()->user(), $aisle);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('aisle_products.mapped_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('sku')->label('SKU')->placeholder('—')->searchable(),
                TextColumn::make('barcode')->placeholder('—')->searchable(),
                TextColumn::make('source')
                    ->label('Added by')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === AisleProduct::SOURCE_COUNT ? 'Found while counting' : 'Mapping')
                    ->color(fn ($state) => $state === AisleProduct::SOURCE_COUNT ? 'warning' : 'gray'),
                TextColumn::make('mapped_at')->label('Mapped')->dateTime('d M Y, g:ia', 'Africa/Lagos')->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add product')
                    ->visible(fn () => $this->mayMap())
                    ->preloadRecordSelect(false)
                    ->recordSelectSearchColumns(['name', 'sku', 'barcode'])
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query
                        ->where('products.vendor_id', $this->getOwnerRecord()->vendor_id)
                        ->whereNull('products.source_product_id')
                        ->where(fn (Builder $q) => $q->where('products.is_service', false)->orWhereNull('products.is_service')))
                    ->mutateDataUsing(fn (array $data) => [
                        ...$data,
                        'vendor_id' => $this->getOwnerRecord()->vendor_id,
                        'source'    => AisleProduct::SOURCE_MAPPING,
                        'mapped_by' => auth()->id(),
                        'mapped_at' => now(),
                    ]),
            ])
            ->recordActions([
                DetachAction::make()->label('Remove')->visible(fn () => $this->mayMap()),
            ])
            ->toolbarActions([
                DetachBulkAction::make()->label('Remove selected')->visible(fn () => $this->mayMap()),
            ]);
    }
}
