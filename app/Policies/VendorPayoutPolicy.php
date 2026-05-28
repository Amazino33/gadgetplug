<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\VendorPayout;
use Illuminate\Auth\Access\HandlesAuthorization;

class VendorPayoutPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VendorPayout');
    }

    public function view(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('View:VendorPayout');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VendorPayout');
    }

    public function update(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('Update:VendorPayout');
    }

    public function delete(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('Delete:VendorPayout');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VendorPayout');
    }

    public function restore(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('Restore:VendorPayout');
    }

    public function forceDelete(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('ForceDelete:VendorPayout');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VendorPayout');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VendorPayout');
    }

    public function replicate(AuthUser $authUser, VendorPayout $vendorPayout): bool
    {
        return $authUser->can('Replicate:VendorPayout');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VendorPayout');
    }

}