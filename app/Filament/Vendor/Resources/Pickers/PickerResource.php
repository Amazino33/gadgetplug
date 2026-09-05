<?php

namespace App\Filament\Vendor\Resources\Pickers;

use App\Filament\Vendor\Resources\Pickers\Pages\ListPickers;
use App\Filament\Vendor\Resources\Pickers\Pages\ViewPicker;
use App\Models\Picker;
use App\Services\ActiveStore;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The traders holding the vendor's goods.
 *
 * Built on the picker rather than the picking, because the question staff ask is
 * "who has our things?" — a list of trips answers a different one, and is what
 * opening a picker shows.
 *
 * Units held and what they are worth are summed from the ledger by subquery.
 * There is no stored figure to read, so nothing here can disagree with the
 * history behind it.
 */
class PickerResource extends Resource
{
    protected static ?string $model = Picker::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-hand-raised';
    protected static string|null|UnitEnum   $navigationGroup = 'Point of Sale';
    protected static ?string                $navigationLabel = 'Vendor Pickings';
    protected static ?string                $modelLabel      = 'picker';
    protected static ?int                   $navigationSort  = 4;

    public static function canAccess(): bool
    {
        $user   = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && (
            $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'manage_pickings')
        );
    }

    public static function canDelete($record): bool { return false; }
    public static function canDeleteAny(): bool     { return false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),

            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(255),

            TextInput::make('shop')
                ->label('Shop')
                ->helperText('Where to find them — the staff know these traders by which shop in the plaza is theirs.')
                ->maxLength(255),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(2),

            Toggle::make('is_active')
                ->label('Still trading')
                ->default(true)
                // Never deleted: a trader who stops has a history of what they
                // took and paid, and that must not leave with them.
                ->helperText('Turn off when they stop taking goods. Their history is kept either way.'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        // The owner sees every branch. Everyone else sees the branch they are
        // standing in — the one they can hand goods out from and collect for.
        // Fails closed, exactly as the debt list does: a staff member with no
        // resolvable branch sees nothing rather than everything.
        $storeId = null;

        if ($user && ! $user->isSuperAdmin() && ! $vendor?->isOwner($user)) {
            $storeId = ActiveStore::currentId();

            if (! $storeId) {
                return parent::getEloquentQuery()->whereRaw('0 = 1');
            }
        }

        return parent::getEloquentQuery()
            ->selectRaw('pickers.*')
            ->selectRaw(self::heldUnitsSql($storeId).' as units_held')
            ->selectRaw(self::heldValueSql($storeId).' as value_out');
    }

    /**
     * Units a picker is still holding: what they took, less everything since
     * paid for, brought back or written off.
     *
     * The branch filter is cast to an integer before it reaches the string, so
     * there is nothing here that could carry a value from outside.
     */
    private static function heldUnitsSql(?int $storeId): string
    {
        $branch = $storeId ? ' and pk.store_id = '.(int) $storeId : '';

        return '('
            .'(select coalesce(sum(pi.quantity), 0) from picking_items pi'
            .' join pickings pk on pk.id = pi.picking_id'
            .' where pk.picker_id = pickers.id'.$branch.')'
            .' - '
            .'(select coalesce(sum(le.quantity), 0) from picking_ledger_entries le'
            .' join picking_items pi on pi.id = le.picking_item_id'
            .' join pickings pk on pk.id = pi.picking_id'
            .' where pk.picker_id = pickers.id'.$branch.')'
            .')';
    }

    /** The same units, valued at what the picker would be asked to pay today. */
    private static function heldValueSql(?int $storeId): string
    {
        $branch = $storeId ? ' and pk.store_id = '.(int) $storeId : '';

        return '('
            .'(select coalesce(sum(pi.quantity * pr.price), 0) from picking_items pi'
            .' join pickings pk on pk.id = pi.picking_id'
            .' join products pr on pr.id = pi.product_id'
            .' where pk.picker_id = pickers.id'.$branch.')'
            .' - '
            .'(select coalesce(sum(le.quantity * pr.price), 0) from picking_ledger_entries le'
            .' join picking_items pi on pi.id = le.picking_item_id'
            .' join pickings pk on pk.id = pi.picking_id'
            .' join products pr on pr.id = pi.product_id'
            .' where pk.picker_id = pickers.id'.$branch.')'
            .')';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPickers::route('/'),
            'view'  => ViewPicker::route('/{record}'),
        ];
    }
}
