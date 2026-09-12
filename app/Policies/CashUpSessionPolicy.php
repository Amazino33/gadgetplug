<?php

namespace App\Policies;

use App\Models\CashUpSession;
use App\Models\User;

/**
 * Authorization for end-of-day cash-ups.
 *
 * The real gate, not a hint: the Filament actions call authorize() rather than
 * relying on ->visible(), so reaching an action directly is refused exactly as
 * if the button had never been rendered. The actions in App\Actions\CashUp
 * enforce the same self-dealing rule a third time, where no UI reaches at all.
 *
 * Gated on receive_cash rather than a new permission of its own. That is already
 * the repo's name for "trusted with other people's money" — it is on store_admin
 * and inventory_manager and deliberately withheld from storekeeper, which is
 * precisely the line a cash-up review has to draw. Inventing a second permission
 * meaning the same thing would only create a way for the two to disagree.
 */
class CashUpSessionPolicy
{
    public function viewAny(User $user): bool
    {
        $vendor = filament()->getTenant();

        return $vendor !== null && $this->reviews($user, (int) $vendor->id);
    }

    public function view(User $user, CashUpSession $session): bool
    {
        // A cashier may always see their own day, whether or not they may review
        // anyone else's. Being told you are short without being allowed to look
        // at the working is not accountability.
        return (int) $session->cashier_id === $user->id
            || $this->reviews($user, (int) $session->vendor_id);
    }

    /**
     * Accounting for part of a difference.
     *
     * Never your own, whatever else you hold — checked before the owner test on
     * purpose, because an owner who also stands at the counter is exactly the
     * case this exists to catch.
     */
    public function rectify(User $user, CashUpSession $session): bool
    {
        if ((int) $session->cashier_id === $user->id) {
            return false;
        }

        return $session->acceptsRectifications()
            && $this->reviews($user, (int) $session->vendor_id);
    }

    /** Signing the day off, which is what makes the remaining difference real. */
    public function approve(User $user, CashUpSession $session): bool
    {
        if ((int) $session->cashier_id === $user->id) {
            return false;
        }

        return $session->isPendingReview()
            && $this->reviews($user, (int) $session->vendor_id);
    }

    /**
     * A cash-up is opened and closed at the till, by the cashier, and never
     * created or edited from the panel. Allowing it here would let a manager
     * write a count that nobody counted.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CashUpSession $session): bool
    {
        return false;
    }

    public function delete(User $user, CashUpSession $session): bool
    {
        return false;
    }

    private function reviews(User $user, int $vendorId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $vendor = \App\Models\Vendor::find($vendorId);

        return $vendor?->isOwner($user) === true
            || $user->hasVendorPermission($vendorId, 'receive_cash');
    }
}
