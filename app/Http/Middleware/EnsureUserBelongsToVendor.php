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

        if ($user->hasRole('super_admin') || $vendor->isOwner($user)) {
            return $next($request);
        }

        if (!$vendor->users()->where('user_id', $user->id)->exists()) {
            abort(403, 'You do not have access to this vendor.');
        }

        setPermissionsTeamId($vendor->id);

        return $next($request);
    }
}