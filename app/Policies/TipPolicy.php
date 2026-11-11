<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tip;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * In-app tips are written by the platform team only: super admins, and anyone
 * granted the global manage_in_app_tips permission. Vendors never write tips
 * (a possible v2), so no vendor role can pass here whatever it holds.
 */
class TipPolicy
{
    public const PERMISSION = 'manage_in_app_tips';

    /** The one rule every In-App Tips screen and action uses. */
    public static function canManage(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || self::holdsGlobally($user, self::PERMISSION));
    }

    /**
     * A permission held with no team: directly, or through a global role.
     *
     * Read straight from the pivots for the same reason User::isSuperAdmin()
     * is. Inside the vendor panel (where "Preview as me" runs) the team
     * resolver falls back to the current tenant even after
     * setPermissionsTeamId(null), so hasGlobalPermission() cannot see a global
     * grant there. See docs/known-issues.md; once that is fixed, use it here.
     */
    public static function holdsGlobally(User $user, string $permission): bool
    {
        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_id', $user->id)
            ->where('model_has_permissions.model_type', $user->getMorphClass())
            ->whereNull('model_has_permissions.team_id')
            ->where('permissions.name', $permission)
            ->exists();

        return $direct || DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->whereNull('model_has_roles.team_id')
            ->where('permissions.name', $permission)
            ->exists();
    }

    public function viewAny(User $user): bool
    {
        return self::canManage($user);
    }

    public function view(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }

    public function create(User $user): bool
    {
        return self::canManage($user);
    }

    public function update(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }

    public function replicate(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }

    public function delete(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return self::canManage($user);
    }

    public function restore(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }

    public function restoreAny(User $user): bool
    {
        return self::canManage($user);
    }

    /** Tip events are history: nobody removes a tip for good. */
    public function forceDelete(User $user, Tip $tip): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** "Preview as me" in the vendor panel. */
    public function preview(User $user, Tip $tip): bool
    {
        return self::canManage($user);
    }
}
