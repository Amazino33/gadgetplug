<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('view_any_products');
    }

    public function view(User $user, Product $product): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('view_products');
    }

    public function create(User $user): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('create_products');
    }

    public function update(User $user, Product $product): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('edit_products');
    }

    public function delete(User $user, Product $product): bool
    {
        $vendor = filament()->getTenant();
        setPermissionsTeamId($vendor?->id);

        return $user->hasRole('super_admin')
            || $vendor?->isOwner($user)
            || $user->hasPermissionTo('delete_products');
    }
}