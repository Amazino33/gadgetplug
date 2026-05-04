<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\HandlesAuthorization;

class VendorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $authUser->hasRole('super_admin')
            || $vendor?->isOwner($authUser)
            || $authUser->can('ViewAny:Vendor')
            || $authUser->hasPermissionTo('view_vendor');
    }

    public function view(AuthUser $authUser, Vendor $vendor): bool
    {
        setPermissionsTeamId($vendor->id);

        return $authUser->hasRole('super_admin')
            || $vendor->isOwner($authUser)
            || $authUser->can('View:Vendor')
            || $authUser->hasPermissionTo('view_vendor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('Create:Vendor');
    }

    public function update(AuthUser $authUser, Vendor $vendor): bool
    {
        setPermissionsTeamId($vendor->id);

        return $authUser->hasRole('super_admin')
            || $vendor->isOwner($authUser)
            || $authUser->can('Update:Vendor')
            || $authUser->hasPermissionTo('edit_vendor');
    }

    public function delete(AuthUser $authUser, Vendor $vendor): bool
    {
        return $authUser->hasRole('super_admin')
            || $vendor->isOwner($authUser)
            || $authUser->can('Delete:Vendor');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('DeleteAny:Vendor');
    }

    public function restore(AuthUser $authUser, Vendor $vendor): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('Restore:Vendor');
    }

    public function forceDelete(AuthUser $authUser, Vendor $vendor): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('ForceDelete:Vendor');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('ForceDeleteAny:Vendor');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('RestoreAny:Vendor');
    }

    public function replicate(AuthUser $authUser, Vendor $vendor): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('Replicate:Vendor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->hasRole('super_admin')
            || $authUser->can('Reorder:Vendor');
    }
}