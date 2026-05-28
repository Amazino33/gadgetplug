<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\VendorApplication;
use Illuminate\Auth\Access\HandlesAuthorization;

class VendorApplicationPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VendorApplication');
    }

    public function view(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('View:VendorApplication');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VendorApplication');
    }

    public function update(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('Update:VendorApplication');
    }

    public function delete(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('Delete:VendorApplication');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VendorApplication');
    }

    public function restore(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('Restore:VendorApplication');
    }

    public function forceDelete(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('ForceDelete:VendorApplication');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VendorApplication');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VendorApplication');
    }

    public function replicate(AuthUser $authUser, VendorApplication $vendorApplication): bool
    {
        return $authUser->can('Replicate:VendorApplication');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VendorApplication');
    }

}