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

    private static function isVendorOwnerOrManager(): bool
    {
        $vendor = filament()->getTenant();
        $user   = auth()->user();

        return $vendor && (
            $user->isSuperAdmin() ||
            $vendor->isOwner($user) ||
            $user->hasVendorRole($vendor->id, ['inventory_manager'])
        );
    }

    public static function canAccess(): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function canViewAny(): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function canCreate(): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function canEdit($record): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function canDelete($record): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function canDeleteAny(): bool
    {
        return static::isVendorOwnerOrManager();
    }

    public static function getEloquentQuery(): Builder
    {
        $vendorId = filament()->getTenant()?->id;

        return parent::getEloquentQuery()->where('team_id', $vendorId);
    }
}
