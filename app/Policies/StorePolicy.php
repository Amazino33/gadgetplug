<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;

// Authorization for managing stores. This is the real gate — the resource and
// its actions call these rather than relying on ->visible(), so a hand-made
// request is refused exactly as a hidden button would have been.
//
// Opening, renaming or closing a branch is a business decision, so it is the
// owner's alone. Deliberately not a Spatie permission: anything grantable from
// the Roles screen could be handed to a storekeeper, and the point is that the
// person who owns the business decides where it trades. Super admins are
// included for the same reason they are everywhere else in this codebase —
// they belong to no vendor and would otherwise be locked out of support work.
class StorePolicy
{
    private function ownsIt(User $user, ?Vendor $vendor): bool
    {
        if ($vendor === null) {
            return false;
        }

        return $user->isSuperAdmin() || $vendor->isOwner($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->ownsIt($user, filament()->getTenant());
    }

    public function view(User $user, Store $store): bool
    {
        return $this->ownsIt($user, $store->vendor);
    }

    public function create(User $user): bool
    {
        return $this->ownsIt($user, filament()->getTenant());
    }

    public function update(User $user, Store $store): bool
    {
        return $this->ownsIt($user, $store->vendor);
    }

    /**
     * Which branch the business falls back to. Guarded further in the action
     * itself — ownership is necessary but not sufficient, because the change
     * is unsafe while stock is reserved at the outgoing default.
     */
    public function setDefault(User $user, Store $store): bool
    {
        return $this->ownsIt($user, $store->vendor);
    }

    public function toggleActive(User $user, Store $store): bool
    {
        return $this->ownsIt($user, $store->vendor);
    }

    /**
     * Who works where. The owner's call rather than a manager's: store access
     * decides which stock a person can move.
     */
    public function assignMembers(User $user, Store $store): bool
    {
        return $this->ownsIt($user, $store->vendor);
    }

    /**
     * Never. Stock rows, order allocations, ledger entries and count sessions
     * all reference a store, and deleting one would orphan the history that
     * explains where goods went. Closing a branch is what deactivate is for.
     */
    public function delete(User $user, Store $store): bool
    {
        return false;
    }
}
