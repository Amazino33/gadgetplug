<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AppFeature;
use App\Models\User;

/**
 * Feature copy is edited by the tip writers. Rows themselves are created and
 * retired only by `php artisan features:sync`, from the code registry.
 */
class AppFeaturePolicy
{
    public function viewAny(User $user): bool
    {
        return TipPolicy::canManage($user);
    }

    public function view(User $user, AppFeature $feature): bool
    {
        return TipPolicy::canManage($user);
    }

    public function update(User $user, AppFeature $feature): bool
    {
        return TipPolicy::canManage($user);
    }

    /** Dragging rows sets the Learn page order. */
    public function reorder(User $user): bool
    {
        return TipPolicy::canManage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, AppFeature $feature): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
