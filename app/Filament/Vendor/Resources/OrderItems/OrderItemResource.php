<?php

namespace App\Filament\Vendor\Resources\OrderItems;

use App\Filament\Vendor\Resources\OrderItems\Pages\CreateOrderItem;
use App\Filament\Vendor\Resources\OrderItems\Pages\EditOrderItem;
use App\Filament\Vendor\Resources\OrderItems\Pages\ListOrderItems;
use App\Filament\Vendor\Resources\OrderItems\Pages\ViewOrderItem;
use App\Filament\Vendor\Resources\OrderItems\Schemas\OrderItemForm;
use App\Filament\Vendor\Resources\OrderItems\Schemas\OrderItemInfolist;
use App\Filament\Vendor\Resources\OrderItems\Tables\OrderItemsTable;
use App\Models\OrderItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderItemResource extends Resource
{
    protected static ?string $model = OrderItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    public static function form(Schema $schema): Schema
    {
        return OrderItemForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderItemInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrderItemsTable::configure($table);
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
            'index' => ListOrderItems::route('/'),
            'view' => ViewOrderItem::route('/{record}'),
            'edit' => EditOrderItem::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (!$vendor) return false;

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorRole($vendor->id, ['order_manager', 'member']);
    }
}
