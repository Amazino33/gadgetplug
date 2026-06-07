<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserBelongsToVendor
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check()) {
            return $next($request);
        }

        $user = auth()->user();
        $vendor = filament()->getTenant();

        // No tenant resolved yet (e.g. on the /plug root redirect) — let it through
        if (!$vendor) {
            return $next($request);
        }

        // isSuperAdmin() does a raw DB check — hasRole() is team-scoped and
        // would miss the global (team_id = NULL) super_admin role here.
        if ($user->isSuperAdmin()) {
            setPermissionsTeamId(null);
            return $next($request);
        }

        if ($vendor->isOwner($user) || $vendor->users()->where('user_id', $user->id)->exists()) {
            setPermissionsTeamId($vendor->id);
            return $next($request);
        }

        abort(403, 'You do not have access to this vendor.');
    }
}