<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Procurement;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProcurementPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Procurement');
    }

    public function view(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('View:Procurement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Procurement');
    }

    public function update(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('Update:Procurement');
    }

    public function delete(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('Delete:Procurement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Procurement');
    }

    public function restore(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('Restore:Procurement');
    }

    public function forceDelete(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('ForceDelete:Procurement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Procurement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Procurement');
    }

    public function replicate(AuthUser $authUser, Procurement $procurement): bool
    {
        return $authUser->can('Replicate:Procurement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Procurement');
    }

}