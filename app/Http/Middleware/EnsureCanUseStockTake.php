<?php

namespace App\Http\Middleware;

use App\Models\StockTakeTask;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StockTake\StockTakeAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Stock Take phone app and everything it calls.
 *
 * Open to members of the shop who hold any Stock Take permission, or who have
 * been given a task that is still open (a mapping task needs no permission of
 * its own). What each person may do inside is checked per action by
 * StockTakeAccess; this only keeps everyone else out of every endpoint.
 */
class EnsureCanUseStockTake
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user   = $request->user();
        $vendor = $request->route('vendor');

        abort_unless($user && $vendor instanceof Vendor, 404);

        // Switched off for this shop: as though it did not exist.
        abort_unless($vendor->hasStockTake(), 404);

        // A counter who has been turned off is out, even on a session still open.
        $counter = $user->counterProfile()->first(); // fresh each time: turning someone off bites at once
        if ($counter && (! $counter->is_active || (int) $counter->vendor_id !== (int) $vendor->id)) {
            abort(403, 'This counter has been turned off. Ask your manager.');
        }

        if (! $user->isSuperAdmin()) {
            $belongs = $vendor->isOwner($user) || $user->memberVendors()->whereKey($vendor->id)->exists();

            $allowed = $belongs && (
                $user->hasVendorPermission($vendor->id, [StockTakeAccess::MANAGE, StockTakeAccess::PERFORM, StockTakeAccess::MAP])
                || StockTakeTask::query()
                    ->where('vendor_id', $vendor->id)
                    ->where('assigned_to', $user->id)
                    ->open()
                    ->exists()
            );

            abort_unless($allowed, 403, 'You do not have access to Stock Take for this shop.');

            // Outside the panel, so the panel's own blocks never see it.
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
