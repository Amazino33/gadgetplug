<?php

namespace App\Filament\Vendor\Resources\Products;

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Filament\Vendor\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $tenantOwnershipRelationshipName = 'vendor';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media');
    }

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorRole($vendor->id, ['owner', 'product_manager', 'member'])
        );
    }

    public static function canCreate(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorRole($vendor->id, ['owner', 'product_manager'])
        );
    }

    public static function canEdit($record): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorRole($vendor->id, ['owner', 'product_manager'])
        );
    }

    public static function canDelete($record): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user)
        );
    }
}
