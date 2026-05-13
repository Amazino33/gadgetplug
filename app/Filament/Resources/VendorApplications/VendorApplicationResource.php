<?php

namespace App\Filament\Resources\VendorApplications;

use App\Filament\Resources\VendorApplications\Pages\ListVendorApplications;
use App\Filament\Resources\VendorApplications\Pages\ViewVendorApplication;
use App\Filament\Resources\VendorApplications\Schemas\VendorApplicationInfolist;
use App\Filament\Resources\VendorApplications\Tables\VendorApplicationsTable;
use App\Models\VendorApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VendorApplicationResource extends Resource
{
    protected static ?string $model = VendorApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Vendor Applications';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Marketplace';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = VendorApplication::where('status', 'pending')->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return VendorApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorApplications::route('/'),
            'view'  => ViewVendorApplication::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
