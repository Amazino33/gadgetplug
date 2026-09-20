<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Procurement;
use App\Models\User;
use App\Services\Auth\StorePermission;

/**
 * Who may receive a delivery, and who may argue with it.
 *
 * The real gate, not a hint. ProcurementReview calls these before it touches
 * anything, so the POS endpoint and the panel are held to the same rule and
 * reaching either one directly is refused exactly as if the button had never
 * rendered. Until now the only thing standing between a storekeeper and their
 * own delivery was a ->visible() on a Filament action.
 *
 * The rule worth stating plainly, because it is the one that looks wrong at a
 * glance: the recorder CAN press approve — but only on a batch the approver has
 * already corrected, and only while it is their turn. That is not somebody
 * waving their own numbers through. The figures being approved are the other
 * party's, and accepting them is what ends the disagreement. What the recorder
 * may never do is approve the batch as they first wrote it.
 */
class ProcurementPolicy
{
    /**
     * Signing the delivery off, which is what puts the goods on the shelf.
     */
    public function approve(User $user, Procurement $procurement): bool
    {
        if (! $procurement->isOpen()) {
            return false;
        }

        if (! $this->isPartyTo($user, $procurement)) {
            return false;
        }

        // Mid-disagreement, only the side being waited on may answer. This is
        // also what stops an approver approving away their own correction the
        // moment after making it.
        if ($procurement->isChangesRequested()) {
            if (! $procurement->isAwaiting($user)) {
                return false;
            }

            // The recorder accepting the other side's figures needs nothing
            // further — it is their own delivery and somebody who could
            // approve has already put their name to these numbers. Anyone else
            // is the approver, and is re-checked in case the permission that
            // let them correct has since been taken away.
            return (int) $user->id === (int) $procurement->created_by
                || $this->mayReceive($user, $procurement);
        }

        return $this->isApproverSide($user, $procurement)
            && $this->mayReceive($user, $procurement);
    }

    /**
     * Saying a line is not what it claims.
     *
     * Open to both sides, but not at the same moment. An approver corrects a
     * batch that has been handed to them; the recorder only ever counter-
     * corrects one that has come back. A recorder "correcting" their own
     * untouched batch would just be editing it, which is the thing the whole
     * arrangement exists to prevent.
     */
    public function correct(User $user, Procurement $procurement): bool
    {
        if (! $procurement->isOpen()) {
            return false;
        }

        if (! $this->isPartyTo($user, $procurement)) {
            return false;
        }

        if ($procurement->isChangesRequested()) {
            // Either the side being waited on, or whoever is mid-way through
            // their own review pass. Corrections are made a line at a time, so
            // the first one hands the batch over while its author is still
            // standing at the pallet with two more to mark — refusing them
            // there would force the whole delivery to be argued one line per
            // round trip.
            if (! $procurement->isAwaiting($user)
                && $procurement->lastCorrectedById() !== (int) $user->id) {
                return false;
            }

            // Symmetric with approve(): the recorder answering about their own
            // delivery needs no permission, anyone else is re-checked in case
            // theirs has since been withdrawn. A delivery left stranded that
            // way is voided, which is the only release valve this build has —
            // there is deliberately no third party who can step in.
            return (int) $user->id === (int) $procurement->created_by
                || $this->mayReceive($user, $procurement);
        }

        return $this->isApproverSide($user, $procurement) && $this->mayReceive($user, $procurement);
    }

    /**
     * Strictly two people.
     *
     * Once somebody other than the recorder has corrected a line they are the
     * counterparty, and a third pair of hands cannot join in — no override, no
     * escalation. Before that first correction the batch is unclaimed, so any
     * eligible approver may pick it up.
     */
    private function isPartyTo(User $user, Procurement $procurement): bool
    {
        $partner = $procurement->reviewPartnerId();

        if ($partner === null) {
            return true;
        }

        return (int) $user->id === $partner
            || (int) $user->id === (int) $procurement->created_by;
    }

    private function isApproverSide(User $user, Procurement $procurement): bool
    {
        if ((int) $user->id !== (int) $procurement->created_by) {
            return true;
        }

        // A shop where nobody else holds approve_procurement has no second
        // person to hand the delivery to. Refusing here would leave the batch
        // pending for ever and the stock never received — so the one-person
        // shop keeps working exactly as it did before this build, and gains
        // the segregation the moment it hires somebody who can approve.
        return ! $procurement->vendor?->hasOtherApprovers((int) $procurement->created_by);
    }

    /**
     * Holds the permission, at this branch.
     *
     * Store-scoped through StorePermission rather than hasVendorPermission
     * alone, for the reason that class exists: goods sent to Oraimo are
     * received by Oraimo, and a vendor-wide permission would let somebody sign
     * for cartons they have never seen.
     */
    private function mayReceive(User $user, Procurement $procurement): bool
    {
        // No destination recorded. These predate the column and belong to no
        // branch, so there is no branch rule to apply to them.
        if ($procurement->store_id === null) {
            return $user->hasVendorPermission((int) $procurement->vendor_id, 'approve_procurement')
                || $user->isSuperAdmin()
                || $user->ownedVendors()->where('id', $procurement->vendor_id)->exists();
        }

        return StorePermission::allows(
            $user,
            (int) $procurement->vendor_id,
            (int) $procurement->store_id,
            'approve_procurement',
        );
    }
}
