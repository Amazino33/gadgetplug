<?php

namespace App\Policies;

use App\Models\PickingItem;
use App\Models\User;
use App\Services\Pickings\PickingLedger;

/**
 * Who may give up on goods a picker has not brought back.
 *
 * The owner alone, which is what the storekeeper's permission set already says:
 * whoever is at the counter hands goods out and takes the money, but deciding
 * the business will not be paid is the owner's call.
 *
 * Deliberately WITHOUT PosCustomerDebtPolicy's rule that whoever granted the
 * credit may not forgive it. That rule exists to stop "sell to a friend, then
 * write it off", and it works there because a manager can grant credit while
 * the owner clears it — two different people. Here only the owner may write off
 * at all, and in this shop the owner is often the one handing goods out
 * himself. Copying the rule would mean nobody could ever write off the owner's
 * own releases, which is a deadlock rather than a control. So the release and
 * the write-off both record who did them, and the history is the check.
 */
class PickingWriteOffPolicy
{
    public function writeOff(User $user, PickingItem $item): bool
    {
        // Nothing still out, nothing to give up on.
        if (PickingLedger::heldQuantity($item) < 1) {
            return false;
        }

        $vendor = $item->picking?->vendor;

        if (! $vendor) {
            return false;
        }

        return $user->isSuperAdmin() || $vendor->isOwner($user);
    }
}
