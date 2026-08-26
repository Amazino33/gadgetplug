<?php

namespace App\Policies;

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\User;

/**
 * Who may forgive a debt.
 *
 * Mirrors InventoryShortageCasePolicy: writing off is the same class of
 * decision as disposing a staff shortage — somebody decides the business will
 * not be paid, and that decision belongs to the owner.
 *
 * The self-disposition rule needs translating rather than copying. There, the
 * debtor is a User and the rule is that nobody disposes what they themselves
 * owe. Here the debtor is a customer, so that reading is vacuous — the risk in
 * this shape is the person who GRANTED the credit being the one who clears it,
 * which is the whole of "sell to a friend, then forgive it". So the block
 * follows the staff member who rang up the credit, not the customer.
 */
class PosCustomerDebtPolicy
{
    public function writeOff(User $user, PosCustomer $customer): bool
    {
        // Nothing owed, nothing to forgive.
        if (! $this->hasOpenBalance($customer)) {
            return false;
        }

        if ($this->grantedTheCredit($user, $customer)) {
            return false;
        }

        return $this->isOwnerOf($user, $customer);
    }

    /**
     * Whether this user rang up any of the credit still outstanding.
     *
     * Any charge, not merely the largest or the latest: a single credit sale of
     * their own inside the balance is enough to make clearing it their own
     * decision about their own conduct.
     */
    public function grantedTheCredit(User $user, PosCustomer $customer): bool
    {
        return PosCustomerLedgerEntry::where('pos_customer_id', $customer->id)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->where('created_by', $user->id)
            ->exists();
    }

    private function hasOpenBalance(PosCustomer $customer): bool
    {
        return (float) PosCustomerLedgerEntry::where('pos_customer_id', $customer->id)->sum('amount') > 0;
    }

    private function isOwnerOf(User $user, PosCustomer $customer): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $customer->vendor?->isOwner($user) === true;
    }
}
