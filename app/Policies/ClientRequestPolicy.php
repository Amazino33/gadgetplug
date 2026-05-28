<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ClientRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class ClientRequestPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ClientRequest');
    }

    public function view(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('View:ClientRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ClientRequest');
    }

    public function update(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('Update:ClientRequest');
    }

    public function delete(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('Delete:ClientRequest');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ClientRequest');
    }

    public function restore(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('Restore:ClientRequest');
    }

    public function forceDelete(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('ForceDelete:ClientRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ClientRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ClientRequest');
    }

    public function replicate(AuthUser $authUser, ClientRequest $clientRequest): bool
    {
        return $authUser->can('Replicate:ClientRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ClientRequest');
    }

}