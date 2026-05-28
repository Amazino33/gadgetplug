<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\AuditSession;
use Illuminate\Auth\Access\HandlesAuthorization;

class AuditSessionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AuditSession');
    }

    public function view(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('View:AuditSession');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AuditSession');
    }

    public function update(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('Update:AuditSession');
    }

    public function delete(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('Delete:AuditSession');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AuditSession');
    }

    public function restore(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('Restore:AuditSession');
    }

    public function forceDelete(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('ForceDelete:AuditSession');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AuditSession');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AuditSession');
    }

    public function replicate(AuthUser $authUser, AuditSession $auditSession): bool
    {
        return $authUser->can('Replicate:AuditSession');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AuditSession');
    }

}