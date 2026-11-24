<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Purchase Terminal and everything it calls: page, lookups and saves.
 *
 * One check in front of the whole group, so no endpoint can be reached by
 * someone the page itself would refuse. Belonging to the shop is checked as
 * well as the permission — roles are team-scoped, but a permission is only
 * meaningful for a shop the person actually works in.
 */
class EnsureCanRecordPurchases
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user   = $request->user();
        $vendor = $request->route('vendor');

        abort_unless($user && $vendor instanceof Vendor, 404);

        if (! $user->isSuperAdmin()) {
            $belongs = $vendor->isOwner($user) || $user->memberVendors()->whereKey($vendor->id)->exists();

            abort_unless(
                $belongs && $user->hasVendorPermission($vendor->id, 'record_purchases'),
                403,
                'You do not have permission to record purchases for this shop.',
            );

            // Outside the panel, so the panel's own block never sees it.
            // Purchases are not part of the free (self-signup) plan.
            if ($vendor->isFreePlan()) {
                return $request->expectsJson()
                    ? response()->json(['message' => Vendor::FREE_PLAN_NO_BACK_OFFICE, 'blocked' => true], 403)
                    : redirect()->to(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
            }

            if ($vendor->isDashboardBlocked()) {
                return $request->expectsJson()
                    ? response()->json(['message' => $vendor->dashboardBlockMessage(), 'blocked' => true], 403)
                    : response()->view('vendor.blocked', ['vendor' => $vendor], 403);
            }
        }

        return $next($request);
    }
}
