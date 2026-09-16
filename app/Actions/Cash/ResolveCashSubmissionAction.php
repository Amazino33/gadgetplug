<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\CashSubmission;
use App\Models\User;
use App\Services\AccountabilityLedger;
use App\Services\Auth\StorePermission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The receiver answers: I got this, or I did not.
 *
 * Only the person named as receiving may answer, and only once. That is the
 * whole control — a handover either has two names agreeing on it or it is
 * visibly outstanding, and neither person can settle it alone.
 */
class ResolveCashSubmissionAction
{
    public function confirm(CashSubmission $submission, User $receiver): CashSubmission
    {
        return $this->resolve($submission, $receiver, function (CashSubmission $row) {
            $row->update([
                'status'       => CashSubmission::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ]);
        });
    }

    /**
     * The receiver says a different amount arrived, or none did.
     *
     * The submitted amount is left exactly as recorded. What each person said
     * at the time is the record, and reconciling it is a conversation between
     * two named people rather than an edit by one of them.
     */
    public function dispute(
        CashSubmission $submission,
        User $receiver,
        string $note,
        ?float $actualAmount = null,
    ): CashSubmission {
        if (blank($note)) {
            throw new RuntimeException('Say what is wrong with it.');
        }

        return $this->resolve($submission, $receiver, function (CashSubmission $row) use ($note, $actualAmount) {
            $row->update([
                'status'          => CashSubmission::STATUS_DISPUTED,
                'disputed_at'     => now(),
                'dispute_note'    => $note,
                'disputed_amount' => $actualAmount !== null ? round($actualAmount, 2) : null,
            ]);
        });
    }

    /**
     * Settle a dispute — the conversation the statement flagged, written down.
     *
     * Neither amount is touched. What each person said at the time stays the
     * record; this adds what was agreed about the difference between them.
     *
     * Charging posts to the same accountability ledger a stock shortage uses,
     * so "what this person owes the business" is one figure rather than one per
     * feature.
     */
    public function settle(
        CashSubmission $submission,
        User $settler,
        string $outcome,
        ?string $note = null,
    ): CashSubmission {
        if (! in_array($outcome, [
            CashSubmission::OUTCOME_ACCEPTED,
            CashSubmission::OUTCOME_CHARGED,
            CashSubmission::OUTCOME_WRITTEN_OFF,
        ], true)) {
            throw new RuntimeException('Say what was agreed about the difference.');
        }

        return DB::transaction(function () use ($submission, $settler, $outcome, $note) {
            $row = CashSubmission::where('id', $submission->id)->lockForUpdate()->firstOrFail();

            if (! $row->isDisputed()) {
                throw new RuntimeException('Only a disputed handover needs settling.');
            }

            $this->guardSettler($row, $settler);

            // The difference put on the person who said they handed it over.
            // Posted as a cash shortage, which is what it is.
            if ($outcome === CashSubmission::OUTCOME_CHARGED && $row->disputedGap() > 0.009) {
                app(AccountabilityLedger::class)->postCashVariance(
                    vendorId: (int) $row->vendor_id,
                    cashierId: (int) $row->submitted_by,
                    // Negative: money that should be there and is not.
                    variance: -1 * $row->disputedGap(),
                    // Idempotent per submission, so a retried settlement cannot
                    // charge the same person twice for the same envelope.
                    naturalKey: "cash_dispute:{$row->id}",
                    storeId: (int) $row->store_id,
                    sourceType: CashSubmission::class,
                    sourceId: (int) $row->id,
                    createdBy: $settler->id,
                    note: $note ?: 'Difference on handover '.$row->reference,
                );
            }

            $row->update([
                'status'             => CashSubmission::STATUS_RESOLVED,
                'resolution_outcome' => $outcome,
                'resolved_by'        => $settler->id,
                'resolved_at'        => now(),
                'resolution_note'    => $note,
            ]);

            return $row->fresh();
        });
    }

    /**
     * Who may settle a disagreement.
     *
     * Not the person who handed the money over — they would be clearing their
     * own debt. Not the person who disputed it either, since they would be
     * ruling on their own account. The owner is exempt: in a small shop they
     * are often one of the two, and leaving a dispute permanently unsettleable
     * is worse than letting the person whose money it is decide.
     */
    private function guardSettler(CashSubmission $row, User $settler): void
    {
        if ($settler->isSuperAdmin() || $settler->ownedVendors()->where('id', $row->vendor_id)->exists()) {
            return;
        }

        if ((int) $row->submitted_by === (int) $settler->id) {
            throw new RuntimeException('You cannot settle a dispute about cash you handed over yourself.');
        }

        if ((int) $row->received_by === (int) $settler->id) {
            throw new RuntimeException('You cannot settle a dispute you raised yourself. Somebody else has to decide it.');
        }

        if (! StorePermission::allows($settler, (int) $row->vendor_id, (int) $row->store_id, 'receive_cash')) {
            throw new RuntimeException('You are not permitted to settle handovers for this branch.');
        }
    }

    private function resolve(CashSubmission $submission, User $receiver, callable $apply): CashSubmission
    {
        return DB::transaction(function () use ($submission, $receiver, $apply) {
            $row = CashSubmission::where('id', $submission->id)->lockForUpdate()->firstOrFail();

            if ($row->received_by !== null) {
                // Nominated in advance: only that person may answer. This also
                // excludes the submitter, who can never be the nominee — a
                // handover to oneself is refused when it is recorded.
                if ((int) $row->received_by !== (int) $receiver->id) {
                    throw new RuntimeException('Only the person it was handed to can answer for it.');
                }
            } else {
                // Nobody was named, so the rules the name was carrying have to
                // be stated outright.
                //
                // A person who can both hand the money over and sign for having
                // received it is accountable to nobody, and the record would
                // prove nothing at all.
                if ((int) $row->submitted_by === (int) $receiver->id) {
                    throw new RuntimeException('You cannot sign for cash you handed over yourself.');
                }

                // The authority to receive cash at this particular branch is
                // what stands in for having been named.
                if (! StorePermission::allows($receiver, (int) $row->vendor_id, (int) $row->store_id, 'receive_cash')) {
                    throw new RuntimeException('You are not permitted to receive cash for this branch.');
                }
            }

            if (! $row->isPending()) {
                throw new RuntimeException('That handover has already been answered.');
            }

            // Whoever actually answered is the receiver on the record, named in
            // advance or not — the point of the row is two people on it.
            $row->received_by = $receiver->id;

            $apply($row);

            return $row->fresh();
        });
    }
}
