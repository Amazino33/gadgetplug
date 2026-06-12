<?php

namespace App\Filament\Vendor\Resources\Roles;

use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Illuminate\Database\Eloquent\Builder;

class RoleResource extends ShieldRoleResource
{
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    private static function canManage(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        return $user->isSuperAdmin() || ($vendor?->isOwner($user) && $vendor->owner_can_manage_roles);
    }
    public static function canAccess(): bool   {
        return static::canManage();
    }
    public static function canViewAny(): bool  {
        return static::canManage();
    }
    public static function canCreate(): bool   {
        return static::canManage();
    }
    public static function canEdit($record): bool   {
        return static::canManage();
    }
    public static function canDelete($record): bool {
        return static::canManage();
    }
    public static function canDeleteAny(): bool     {
        return static::canManage();
    }

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
