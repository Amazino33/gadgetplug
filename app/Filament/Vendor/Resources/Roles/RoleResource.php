<?php

namespace App\Filament\Vendor\Resources\Roles;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends ShieldRoleResource
{
    // We scope via team_id in getEloquentQuery(); Filament's relationship-based
    // tenant scoping would look for a non-existent 'vendors' relation on Role.
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canAccess(): bool   { return auth()->user()->isSuperAdmin(); }
    public static function canViewAny(): bool  { return auth()->user()->isSuperAdmin(); }
    public static function canCreate(): bool   { return auth()->user()->isSuperAdmin(); }
    public static function canEdit($record): bool   { return auth()->user()->isSuperAdmin(); }
    public static function canDelete($record): bool { return auth()->user()->isSuperAdmin(); }
    public static function canDeleteAny(): bool     { return auth()->user()->isSuperAdmin(); }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        // Abort with empty result rather than leaking global (team_id = NULL) roles
        if (! $vendorId) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        return parent::getEloquentQuery()->where('team_id', $vendorId);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view'   => Pages\ViewRole::route('/{record}'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
