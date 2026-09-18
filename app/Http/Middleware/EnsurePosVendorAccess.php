<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The POS API identifies its store with a `vendor_id` sent by the till, and
 * until this existed every endpoint but login simply believed it. An
 * authenticated cashier could post another vendor's id and transact in their
 * books — the sale was written, their stock was decremented and their revenue
 * ledger was posted to, all under a token that never belonged to them.
 *
 * Authentication was never the gap; authorisation to a PARTICULAR vendor was.
 * That check lives here, once, in front of the whole group, rather than in each
 * controller where the next endpoint would forget it exactly as these did.
 */
class EnsurePosVendorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // The vendor the till named. Refusing this with 403 is right: the
        // caller said which store it meant, so telling it plainly that it may
        // not act there reveals nothing it did not already supply.
        $sent = $request->input('vendor_id', $request->query('vendor_id'));

        if (filled($sent) && is_numeric($sent) && ! $this->mayActFor($user, (int) $sent)) {
            abort(403, 'You do not have access to this store.');
        }

        // A vendor reached through a route-bound record is different: answering
        // 403 would confirm that a record with that id exists under someone
        // else. 404 is both the safer answer and the one a correctly scoped
        // controller already gives, so this stays consistent with them.
        foreach ($this->boundVendorIds($request) as $vendorId) {
            if (! $this->mayActFor($user, $vendorId)) {
                abort(404);
            }
        }

        // Access is fine; the account is not. A token issued before the block
        // would otherwise keep trading indefinitely, since tokens are long
        // lived and the till only re-authenticates when someone logs out.
        if (! $user->isSuperAdmin()) {
            $touched = array_unique(array_merge(
                filled($sent) && is_numeric($sent) ? [(int) $sent] : [],
                $this->boundVendorIds($request),
            ));

            $blocked = $touched
                ? Vendor::whereKey($touched)->where('dashboard_blocked', true)->first()
                : null;

            if ($blocked) {
                return response()->json([
                    'message' => $blocked->dashboardBlockMessage(),
                    'blocked' => true,
                ], 403);
            }
        }

        return $next($request);
    }

    /**
     * Vendors reached through a route-bound record — /sales/{sale}/void names
     * no vendor_id at all, but the sale it points at belongs to one.
     *
     * @return array<int, int>
     */
    private function boundVendorIds(Request $request): array
    {
        $ids = [];

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && filled($parameter->vendor_id ?? null)) {
                $ids[] = (int) $parameter->vendor_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function mayActFor(User $user, int $vendorId): bool
    {
        // Platform staff already reach every vendor through the admin panel, so
        // refusing them here would only make support work harder without
        // closing anything.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Same two relations PosAuthController checks when it issues the token —
        // this simply keeps checking on every request the token is used for.
        return $user->ownedVendors()->whereKey($vendorId)->exists()
            || $user->memberVendors()->whereKey($vendorId)->exists();
    }
}
