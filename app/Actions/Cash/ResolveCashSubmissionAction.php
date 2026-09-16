<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\CashSubmission;
use App\Models\User;
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
