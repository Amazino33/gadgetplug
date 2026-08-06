<?php

namespace App\Filament\Resources\AffiliateCommissions;

use App\Filament\Resources\AffiliateCommissions\Tables\AffiliateCommissionsTable;
use App\Models\AffiliateCommission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

// Read-only, cross-affiliate oversight — mirrors the admin Orders/PosSales
// pattern. The per-affiliate commission list already exists as a relation
// manager on the Affiliate resource; this is the platform-wide view across
// every affiliate at once, filterable by status.
class AffiliateCommissionResource extends Resource
{
    protected static ?string $model = AffiliateCommission::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string                $navigationLabel = 'Commissions';
    protected static ?int                   $navigationSort  = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool   { return false; }
    public static function canEdit($record): bool   { return false; }
    public static function canDelete($record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['affiliate.user', 'order'])->latest();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return AffiliateCommissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliateCommissions::route('/'),
        ];
    }
}
