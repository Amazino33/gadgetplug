<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

// Which store the user is currently operating in, inside the vendor panel.
//
// The store is a context, never a tenant: the vendor remains the Filament
// tenant and the Spatie team, and every permission check still keys off the
// vendor. This only decides which of that vendor's stores the inventory
// screens act on, and it is held in the session, keyed per vendor so switching
// vendors cannot carry a store across.
//
// Access rule, one place, used by the guard and the UI alike:
//   owner (or super admin) → every store the vendor has
//   anyone else           → exactly their store_user assignments
// Owner access runs through vendors.user_id, the same path isOwner()/canAccess()
// have always used — no owner rows are invented in store_user to make this work.
class ActiveStore
{
    private const SESSION_PREFIX = 'active_store.';

    /**
     * Every store this user may operate in, for this vendor.
     *
     * @return Collection<int, Store>
     */
    public static function accessibleFor(Vendor $vendor, User $user): Collection
    {
        $query = Store::query()->where('vendor_id', $vendor->id);

        // Super admins are not members of anything, so the membership branch
        // would lock them out of a panel they can otherwise fully administer.
        if ($user->isSuperAdmin() || $vendor->isOwner($user)) {
            return $query->orderByDesc('is_default')->orderBy('name')->get();
        }

        return $query
            ->whereIn('id', fn ($q) => $q
                ->select('store_id')
                ->from('store_user')
                ->where('user_id', $user->id))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public static function canAccess(Vendor $vendor, User $user, int $storeId): bool
    {
        return self::accessibleFor($vendor, $user)->contains('id', $storeId);
    }

    /**
     * The active store, resolving and remembering one if none is set yet.
     * Null only when the user may reach no store at all — a member assigned to
     * nothing — which the selector page reports rather than crashing on.
     */
    public static function get(Vendor $vendor, User $user): ?Store
    {
        $accessible = self::accessibleFor($vendor, $user);

        if ($accessible->isEmpty()) {
            return null;
        }

        $storedId = Session::get(self::sessionKey($vendor));

        // Re-checked on every read, not just on set: a member's assignment can
        // be revoked while they are sitting on the page, and the session must
        // not keep handing them a store they no longer hold.
        if ($storedId !== null) {
            $current = $accessible->firstWhere('id', (int) $storedId);

            if ($current) {
                return $current;
            }

            Session::forget(self::sessionKey($vendor));
        }

        $resolved = $accessible->firstWhere('is_default', true) ?? $accessible->first();

        Session::put(self::sessionKey($vendor), $resolved->id);

        return $resolved;
    }

    /**
     * Switch stores. Returns false and changes nothing when the store is not
     * this vendor's or not in the user's accessible set — the guard lives here
     * rather than in the UI, so a hand-crafted request is refused too.
     */
    public static function set(Vendor $vendor, User $user, Store|int $store): bool
    {
        $storeId = $store instanceof Store ? $store->id : $store;

        if (! self::canAccess($vendor, $user, $storeId)) {
            return false;
        }

        Session::put(self::sessionKey($vendor), $storeId);

        return true;
    }

    public static function forget(Vendor $vendor): void
    {
        Session::forget(self::sessionKey($vendor));
    }

    /**
     * The active store id for the current panel request, or null outside one.
     * The stock actions use this to target the store the operator is actually
     * standing in; away from the panel (checkout, POS, order observer) there is
     * no such context and the actions keep their default-store fallback.
     */
    public static function currentId(): ?int
    {
        $vendor = filament()->getTenant();
        $user = auth()->user();

        if (! $vendor instanceof Vendor || ! $user) {
            return null;
        }

        return self::get($vendor, $user)?->id;
    }

    private static function sessionKey(Vendor $vendor): string
    {
        return self::SESSION_PREFIX.$vendor->id;
    }
}
