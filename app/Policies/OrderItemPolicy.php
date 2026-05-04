<?php

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;

class OrderItemPolicy
{
    public function viewAny(User $user): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('view_any_order_items');
    }

    public function view(User $user, OrderItem $orderItem): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('view_order_items');
    }

    public function create(User $user): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin') || $vendor?->isOwner($user);
    }

    public function update(User $user, OrderItem $orderItem): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('edit_order_items');
    }

    public function delete(User $user, OrderItem $orderItem): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin') || $vendor?->isOwner($user);
    }
}