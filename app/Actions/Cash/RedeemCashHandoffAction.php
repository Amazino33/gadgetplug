<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\CashSubmission;
use App\Models\User;
use App\Services\Cash\CashHandoffToken;
use RuntimeException;

/**
 * The receiving half of a handover, driven by the code on the submitter's screen.
 *
 * Scanning proves presence: the person answering for the money was standing in
 * front of the person handing it over, within the life of the code. That is the
 * part a list of names in a dropdown could never establish, and it is why the
 * token is short-lived and single-use rather than a permanent link to the
 * submission.
 */
class RedeemCashHandoffAction
{
    public function __construct(private readonly ResolveCashSubmissionAction $resolver) {}

    /**
     * What this code refers to, without spending it.
     *
     * The receiver has to see the claimed amount before they can agree or
     * disagree with it, so looking is free.
     */
    public function inspect(string $token): CashSubmission
    {
        $payload = CashHandoffToken::peek($token);

        if ($payload === null) {
            throw new RuntimeException('That code has expired or has already been used. Ask for a new one.');
        }

        $submission = CashSubmission::find($payload['submission_id']);

        if (! $submission) {
            throw new RuntimeException('That handover no longer exists.');
        }

        return $submission;
    }

    public function confirm(string $token, User $receiver): CashSubmission
    {
        return $this->redeem($token, fn (CashSubmission $s) => $this->resolver->confirm($s, $receiver));
    }

    public function dispute(string $token, User $receiver, string $note, ?float $actualAmount = null): CashSubmission
    {
        return $this->redeem($token, fn (CashSubmission $s) => $this->resolver->dispute($s, $receiver, $note, $actualAmount));
    }

    /**
     * The token is spent only once the answer has actually been recorded.
     *
     * Burning it first would mean a scan by somebody without permission, or of
     * a handover already answered, destroys a code the right person still needs
     * — and the storekeeper would be standing there with the cash and no way to
     * hand it over. Double use is prevented by the row itself, which is locked
     * and refuses a second answer.
     */
    private function redeem(string $token, callable $answer): CashSubmission
    {
        $submission = $this->inspect($token);

        $resolved = $answer($submission);

        CashHandoffToken::revoke($token);

        return $resolved;
    }
}
