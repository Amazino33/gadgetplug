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
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    // Filament v5 routes action visibility through getXxxAuthorizationResponse(),
    // not canXxx(). These bridge back to the vendor-role logic above so that the
    // Create / Edit / Delete buttons respect the same rules as page access.
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::canEdit($record) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::canDelete($record) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        $allowed = $vendor && ($user->isSuperAdmin() || $vendor->isOwner($user));
        return $allowed ? Response::allow() : Response::deny();
    }
}
