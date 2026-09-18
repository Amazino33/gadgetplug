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
            // Belongs here, but the account itself is blocked over money or
            // terms. Rendered as a response rather than abort(403): the 403
            // handler in bootstrap/app.php bounces a signed-in user back to
            // their panel home, which is this exact url — a redirect loop, and
            // a page that never says why they were turned away.
            if ($vendor->isDashboardBlocked()) {
                return $this->blocked($request, $vendor);
            }

            setPermissionsTeamId($vendor->id);
            return $next($request);
        }

        abort(403, 'You do not have access to this vendor.');
    }

    /**
     * Livewire can't follow a rendered page, so the panel's own background
     * requests get a plain 403 and the next full page load shows the notice.
     */
    private function blocked(Request $request, \App\Models\Vendor $vendor)
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, $vendor->dashboardBlockMessage());
        }

        return response()->view('vendor.blocked', ['vendor' => $vendor], 403);
    }
}