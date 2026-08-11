<?php

namespace App\Policies;

use App\Models\InventoryShortageCase;
use App\Models\User;

// Authorization for shortage cases. This is the real gate — the Filament
// actions call authorize() rather than relying on ->visible(), so hitting the
// action directly is denied just the same as never seeing the button.
class InventoryShortageCasePolicy
{
    public function viewAny(User $user): bool
    {
        $vendor = filament()->getTenant();

        return $vendor !== null && $user->hasVendorPermission($vendor->id, 'view_audit_sessions');
    }

    public function view(User $user, InventoryShortageCase $case): bool
    {
        return $user->hasVendorPermission($case->vendor_id, 'view_audit_sessions');
    }

    /**
     * Deciding a named person owes money is the owner's call.
     *
     * Deliberately not a Spatie permission: anything grantable from the Roles
     * screen could be handed to a manager, and the whole point is that the
     * person who runs the store carries this one.
     */
    public function dispose(User $user, InventoryShortageCase $case): bool
    {
        // Nobody disposes their own shortage, whatever else they hold. Checked
        // before the owner test on purpose — an owner who is also the charged
        // party is exactly the case this must catch.
        if ($case->charged_storekeeper_id !== null && $case->charged_storekeeper_id === $user->id) {
            return false;
        }

        // A finished case is not re-openable; charged and written_off are
        // financial facts, not workflow states.
        if (! $case->awaitsDisposition()) {
            return false;
        }

        return $this->isOwnerOf($user, $case);
    }

    /**
     * Recording money back, or abandoning the remainder.
     *
     * Owner-only like disposition, and with the same self-dealing block: a
     * person must not be able to mark their own debt as recovered, or write off
     * what they themselves owe.
     */
    public function recordRecovery(User $user, InventoryShortageCase $case): bool
    {
        if ($case->charged_storekeeper_id !== null && $case->charged_storekeeper_id === $user->id) {
            return false;
        }

        // Only a live charge can be recovered against.
        if ($case->status !== 'charged') {
            return false;
        }

        return $this->isOwnerOf($user, $case);
    }

    /** Changing who carries the loss is the same class of decision. */
    public function reassign(User $user, InventoryShortageCase $case): bool
    {
        if (! $case->awaitsDisposition()) {
            return false;
        }

        // Reassigning a case onto yourself and then disposing it would route
        // straight around the self-disposition block, so it is refused here too.
        if ($case->charged_storekeeper_id !== null && $case->charged_storekeeper_id === $user->id) {
            return false;
        }

        return $this->isOwnerOf($user, $case);
    }

    private function isOwnerOf(User $user, InventoryShortageCase $case): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $case->vendor?->isOwner($user) === true;
    }
}
