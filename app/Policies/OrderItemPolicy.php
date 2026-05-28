<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\OrderItem;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderItemPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderItem');
    }

    public function view(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('View:OrderItem');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderItem');
    }

    public function update(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('Update:OrderItem');
    }

    public function delete(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('Delete:OrderItem');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderItem');
    }

    public function restore(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('Restore:OrderItem');
    }

    public function forceDelete(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('ForceDelete:OrderItem');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OrderItem');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OrderItem');
    }

    public function replicate(AuthUser $authUser, OrderItem $orderItem): bool
    {
        return $authUser->can('Replicate:OrderItem');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OrderItem');
    }

}