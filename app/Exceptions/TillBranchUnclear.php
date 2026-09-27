<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The till could not tell which branch it is standing in.
 *
 * Refused rather than guessed. The guess used to be the vendor's default
 * store, which is where a day of Zeelink Phones sales was checked on
 * 26/09/2026 — a branch that held none of those phones — so every sale came
 * back as "Insufficient stock" with the goods already gone out of the door.
 *
 * The code lets the till tell this apart from any other 422: it means "sign in
 * again and choose the branch", not "fix this sale".
 */
class TillBranchUnclear extends RuntimeException
{
    public const CODE = 'till_branch_unclear';

    public static function noBranch(): self
    {
        return new self(
            'You are not assigned to any branch of this business, so the till cannot tell whose stock to use. Ask a manager to assign you to your branch.'
        );
    }

    public static function severalBranches(): self
    {
        return new self(
            'You work in more than one branch. Sign out of the till and sign in again, choosing the branch you are in.'
        );
    }

    public static function noLongerAssigned(): self
    {
        return new self(
            'You are no longer assigned to the branch this till was signed in to. Sign out of the till and sign in again.'
        );
    }

    public static function notYourBranch(string $storeName): self
    {
        return new self(
            "This sale was rung at {$storeName}, which you are not assigned to. It will sync when someone from that branch signs in on this till."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => self::CODE,
        ], 422);
    }
}
