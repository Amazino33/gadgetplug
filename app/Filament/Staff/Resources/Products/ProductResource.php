<?php

namespace App\Filament\Staff\Resources\Products;

use App\Filament\Resources\Products\ProductResource as AdminProductResource;
use App\Filament\Staff\Resources\Products\Pages\ListProducts;
use App\Filament\Staff\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

// The staff catalogue: every vendor's products in one list, so back-office
// staff fix photos and online names without entering the admin panel. Cost is
// never loaded or shown here — staff see the same safe column whitelist the
// admin panel gives its catalogue editor, and nothing else.
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'All Products';

    protected static ?string $modelLabel = 'product';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Catalogue';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewCatalogue', Product::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(array_map(fn (string $column) => "products.{$column}", AdminProductResource::SAFE_COLUMNS))
            ->with(['vendor:id,name', 'category:id,name', 'supplierLink.supplier:id,name', 'media', 'sourceProduct.media'])
            ->withCount([
                'media as image_count' => fn (Builder $q) => $q->where('collection_name', 'product-images'),
                'resoldListings',
            ]);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
        ];
    }
}
