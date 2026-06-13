<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Vendor;
use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    private function isVendorOwnerOrManager(AuthUser $user): bool
    {
        $vendor = filament()->hasTenancy() ? filament()->getTenant() : null;

        if (! $vendor instanceof Vendor) {
            return false;
        }

        return $user->isSuperAdmin()
            || $vendor->isOwner($user)
            || $user->hasVendorPermission($vendor->id, 'edit_vendor');
    }

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('ViewAny:Role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('View:Role');
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('Create:Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('Update:Role');
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('Delete:Role');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->isVendorOwnerOrManager($authUser) || $authUser->can('DeleteAny:Role');
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Restore:Role');
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('ForceDelete:Role');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Role');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Role');
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Replicate:Role');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Role');
    }

}