<?php

namespace App\Filament\Vendor\Resources\Procurements;

use App\Filament\Vendor\Resources\Procurements\Schemas\ProcurementForm;
use App\Filament\Vendor\Resources\Procurements\Tables\ProcurementsTable;
use App\Models\Procurement;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static ?string $tenantOwnershipRelationshipName = 'vendor';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|null|\UnitEnum $navigationGroup = 'Procurement';

    protected static ?string $navigationLabel = 'Procurements';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('procurements')) {
            return false;
        }

        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'manage_inventory');
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $vendor && $user->hasVendorPermission($vendor->id, 'create_procurement');
    }

    // Editable only while the auto-pricing workflow is still in flight
    // (draft: storekeeper edits lines; awaiting_logistics: logistics staff
    // enters the trip cost). Reconciled/voided/legacy pending|approved
    // records are read-only — matches "never re-open a reconciled procurement".
    public static function canEdit($record): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        if (! $vendor || ! in_array($record->status, ['draft', 'awaiting_logistics'], true)) {
            return false;
        }

        return $user->hasVendorPermission($vendor->id, ['submit_procurement', 'record_procurement_logistics']);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ProcurementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProcurementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProcurements::route('/'),
            'create' => Pages\CreateProcurement::route('/create'),
            'view' => Pages\ViewProcurement::route('/{record}'),
            'edit' => Pages\EditProcurement::route('/{record}/edit'),
        ];
    }
}
