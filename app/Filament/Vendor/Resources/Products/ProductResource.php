<?php

namespace App\Filament\Vendor\Resources\Products;

use App\Filament\Vendor\Resources\Products\Pages\CreateProduct;
use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Resources\Products\Pages\ViewProduct;
use App\Filament\Vendor\Resources\Products\Schemas\ProductForm;
use App\Filament\Vendor\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Services\ActiveStore;
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
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    // The store is a filter layered on top of Filament's vendor tenancy, never
    // a tenant of its own: parent::getEloquentQuery() still applies the vendor
    // scope through $tenantOwnershipRelationshipName, and this narrows that
    // result to the store the user is currently working in.
    //
    // Only products HOMED at this store appear, which is what makes two stores
    // show different catalogues. Home store rather than "holds a row here": a
    // product belongs to one branch, and it must stay in that branch's list
    // even when it has sold out — a line at zero is precisely the one someone
    // needs to see and reorder. The two selected columns still come from that
    // store's stock row, so the numbers on screen are this store's.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('media');

        $storeId = ActiveStore::currentId();

        if ($storeId === null) {
            return $query;
        }

        return $query
            ->where('products.store_id', $storeId)
            // products.* explicitly: addSelect() on a query with no select list
            // yet REPLACES it with just these subqueries, which would strip the
            // model down to two columns and leave the table with no id or name.
            ->select('products.*')
            ->addSelect([
                'store_quantity' => ProductStoreStock::select('quantity')
                    ->whereColumn('product_id', 'products.id')
                    ->where('store_id', $storeId)
                    ->limit(1),
                'store_reserved' => ProductStoreStock::select('reserved')
                    ->whereColumn('product_id', 'products.id')
                    ->where('store_id', $storeId)
                    ->limit(1),
            ]);
    }

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorPermission($vendor->id, 'view_any_products')
        );
    }

    public static function canCreate(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorPermission($vendor->id, 'create_products')
        );
    }

    public static function canView($record): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorPermission($vendor->id, 'view_products')
        );
    }

    public static function canEdit($record): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $user->hasVendorPermission($vendor->id, 'edit_products')
        );
    }

    public static function canDelete($record): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();
        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorPermission($vendor->id, 'delete_products')
        );
    }

    // Filament v5 routes action visibility through getXxxAuthorizationResponse(),
    // not canXxx(). These bridge back to the vendor-role logic above so that the
    // Create / Edit / Delete buttons respect the same rules as page access.
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::canCreate() ? Response::allow() : Response::deny();
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::canView($record) ? Response::allow() : Response::deny();
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
