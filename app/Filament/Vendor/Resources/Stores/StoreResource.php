<?php

namespace App\Filament\Vendor\Resources\Stores;

use App\Filament\Vendor\Resources\Stores\Pages\CreateStore;
use App\Filament\Vendor\Resources\Stores\Pages\EditStore;
use App\Filament\Vendor\Resources\Stores\Pages\ListStores;
use App\Filament\Vendor\Resources\Stores\Schemas\StoreForm;
use App\Filament\Vendor\Resources\Stores\Tables\StoresTable;
use App\Models\Store;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

// Where an owner opens, renames and closes branches. Every prior phase assumed
// stores already existed — they could only be made by the vendor observer or by
// hand — so this is the piece that makes the rest of multi-store usable by
// someone who is not sitting at a database prompt.
//
// Authorization goes through StorePolicy, not these methods alone: they decide
// what is drawn, the policy decides what is permitted, and the policy is what a
// forged request meets.
class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    // Filament's tenancy is turned OFF for this resource on purpose, and the
    // vendor filter applied by hand below instead.
    //
    // Filament implements resource tenancy by registering a GLOBAL SCOPE on
    // the model (BelongsToTenant::registerGlobalScope), not by scoping this
    // resource's queries. Leaving it on means every Store query anywhere in
    // the application — a plain $vendor->defaultStore relation included — is
    // silently filtered to whichever tenant happens to be active. That broke a
    // cross-vendor read the moment this resource existed, and would have been
    // far worse unseen: DefaultStore::seedFor() looks for an existing default
    // before creating one, and a scoped-away result would have had it create a
    // second "Main Store" for a vendor that already had one.
    //
    // Every other vendor-owned query in this codebase scopes manually. This
    // one now does too.
    protected static bool $isScopedToTenant = false;

    protected static string|null|\BackedEnum $navigationIcon  = Heroicon::OutlinedBuildingStorefront;
    protected static string|null|UnitEnum    $navigationGroup = 'Settings';
    protected static ?string                 $navigationLabel = 'Manage Stores';
    protected static ?int $navigationSort = 5;
    protected static ?string                 $modelLabel      = 'store';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Store::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', Store::class) ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    // Stock, order allocations, ledger entries and count sessions all point at
    // a store. Deleting one would orphan the history explaining where goods
    // went, so a closed branch is deactivated and kept.
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->where('vendor_id', filament()->getTenant()?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return StoreForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoresTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListStores::route('/'),
            'create' => CreateStore::route('/create'),
            'edit'   => EditStore::route('/{record}/edit'),
        ];
    }
}
