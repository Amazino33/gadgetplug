<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\CashSubmission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The single-use code on a handover's QR.
 *
 * Held in the cache rather than a column, following the vendor-invite pattern
 * already in this codebase: expiry is the store's own problem to enforce, and a
 * cache entry that ages out on its own cannot be left behind as a live token by
 * a missed cleanup.
 *
 * Random rather than derived from the submission id. A sequential code could be
 * guessed from a handover somebody legitimately saw, and confirming a handover
 * you were never handed is exactly the move this is built to prevent.
 *
 * Reading the code is not the same as spending it: the receiver has to see the
 * claimed amount before they can agree or disagree with it, so peek() is free
 * and only the answer itself consumes the token.
 */
class CashHandoffToken
{
    /**
     * Long enough to walk the money to an office, short enough that a code
     * photographed off somebody's screen is useless by the time it is copied.
     */
    public const TTL_MINUTES = 30;

    private const PREFIX = 'cash_handoff_';

    public static function issue(CashSubmission $submission): string
    {
        $token = Str::random(40);

        Cache::put(self::key($token), [
            'submission_id' => $submission->id,
            'vendor_id'     => $submission->vendor_id,
            'store_id'      => $submission->store_id,
            'submitted_by'  => $submission->submitted_by,
            'issued_at'     => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /** What this token refers to, without spending it. Null once expired or used. */
    public static function peek(string $token): ?array
    {
        return Cache::get(self::key($token));
    }

    /**
     * Spend the token.
     *
     * Forgotten before the caller acts on it, so a second scan of the same code
     * finds nothing even if the first one goes on to fail. A handover confirmed
     * twice would be a second receipt for money that only moved once.
     */
    public static function consume(string $token): ?array
    {
        $key = self::key($token);
        $payload = Cache::get($key);

        if ($payload === null) {
            return null;
        }

        Cache::forget($key);

        return $payload;
    }

    /** Withdraw a code that was issued but never used. */
    public static function revoke(string $token): void
    {
        Cache::forget(self::key($token));
    }

    private static function key(string $token): string
    {
        return self::PREFIX . $token;
    }
}
