<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

// All Products: every vendor's catalogue in one place, for the platform.
//
// Two audiences. Super-admins also set the affiliate commission_rate and
// reseller_discount overrides here (the platform bears both costs, so those
// fields stay off the vendor form) — that is the Edit page. The catalogue
// editor fixes photos (and later online names) through row actions and never
// reaches the Edit page.
//
// The editor must never be handed cost: not shown, not loaded. When the user
// may not see cost, the query names its columns explicitly, so cost_price and
// anything derived from it never reaches the model, the page or Livewire state.
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-cube';
    protected static ?string                $navigationLabel = 'All Products';
    protected static ?string                $modelLabel      = 'product';
    protected static ?int                   $navigationSort  = 5;

    /**
     * What a user without cost visibility may load. Deliberately a whitelist:
     * a column added to products later stays hidden until someone decides it
     * is safe to show here.
     */
    public const SAFE_COLUMNS = [
        'id', 'vendor_id', 'store_id', 'category_id',
        'source_product_id', 'supplier_link_id',
        'name', 'slug', 'brand', 'description', 'specifications',
        'status', 'show_online', 'published_at', 'unpublish_at',
        // Selling price only. has_custom_price is needed by the price accessor
        // to decide whether a linked listing quotes its own price.
        'price', 'has_custom_price',
        'created_at', 'updated_at',
    ];

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewCatalogue', Product::class);
    }

    // Super-admin only: cost, margins and the commission/discount overrides.
    // The catalogue editor holds no view_cost_price in any team.
    public static function canSeeCost(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool { return false; }

    public static function canDelete($record): bool { return false; }

    // The Edit page is the commission/discount form — super-admin only.
    public static function canEdit($record): bool
    {
        return static::canSeeCost();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::canSeeCost()) {
            $query->select(array_map(fn (string $column) => "products.{$column}", self::SAFE_COLUMNS));
        }

        return $query
            ->with(['vendor:id,name', 'category:id,name', 'supplierLink.supplier:id,name', 'media'])
            ->withCount([
                'media as image_count' => fn (Builder $q) => $q->where('collection_name', 'product-images'),
                'resoldListings',
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'edit'  => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
