<?php

namespace App\Filament\Vendor\Resources\Orders;

use App\Filament\Vendor\Resources\Orders\Pages\ListOrders;
use App\Filament\Vendor\Resources\Orders\Pages\ViewOrder;
use App\Filament\Vendor\Resources\Orders\RelationManagers\DeliveryMessagesRelationManager;
use App\Filament\Vendor\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Vendor\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static string|null|\UnitEnum $navigationGroup = 'Orders';
    protected static ?string $model = Order::class;

    // Orders don't have a direct vendor relationship — scoping is handled in getEloquentQuery()
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Orders';

    protected static ?int $navigationSort = 1;

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        return parent::getEloquentQuery()
            ->whereHas('items', fn(Builder $q) => $q->where('vendor_id', $vendorId))
            ->with(['items.product', 'user'])
            ->latest();
    }

    public static function getRelations(): array
    {
        return [
            DeliveryMessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view'  => ViewOrder::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return static::isAuthorized();
    }

    private static function isAuthorized(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        // Online orders are the whole point of this resource — a vendor with
        // online sales switched off loses it entirely, same as any other
        // permission-gated page. Super admin still needs to reach it for
        // support/audit even while a vendor is disabled.
        return $vendor && (
            $user->isSuperAdmin() ||
            ($vendor->canSellOnline() && (
                $vendor->isOwner($user) ||
                $user->hasVendorPermission($vendor->id, 'view_any_order_items')
            ))
        );
    }

    public static function getViewAuthorizationResponse(Model $record): Response
    {
        return static::isAuthorized() ? Response::allow() : Response::deny();
    }
}
