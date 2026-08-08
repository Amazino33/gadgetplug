<?php

namespace App\Filament\Resources\AffiliateApplications;

use App\Filament\Resources\AffiliateApplications\Pages\ListAffiliateApplications;
use App\Filament\Resources\AffiliateApplications\Pages\ViewAffiliateApplication;
use App\Filament\Resources\AffiliateApplications\Schemas\AffiliateApplicationInfolist;
use App\Filament\Resources\AffiliateApplications\Tables\AffiliateApplicationsTable;
use App\Models\AffiliateApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class AffiliateApplicationResource extends Resource
{
    protected static ?string $model = AffiliateApplication::class;

    protected static string|null|BackedEnum $navigationIcon  = 'heroicon-o-user-plus';
    protected static ?string                $navigationLabel = 'Applications';
    protected static ?int                   $navigationSort  = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Affiliates';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AffiliateApplication::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AffiliateApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AffiliateApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateApplications::route('/'),
            'view'  => ViewAffiliateApplication::route('/{record}'),
        ];
    }
}
