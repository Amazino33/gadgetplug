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
use UnitEnum;

// Deliberately narrow: this exists only so a super-admin can set the
// affiliate commission_rate and reseller_discount overrides on a product.
// Vendors control every other product field on their own ProductForm — the
// platform bears the cost of both affiliate commissions and reseller
// discounts, not the vendor, so these fields stay admin-only rather than
// living on the vendor-facing form. Everything else here is read-only
// context, not a general-purpose product editor.
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-cube';
    protected static ?string                $navigationLabel = 'Products';
    protected static ?int                   $navigationSort  = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vendor', 'category']);
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
