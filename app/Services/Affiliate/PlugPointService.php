<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateTaskSubmission;
use App\Models\PlugPointTransaction;

/**
 * The Plug Points economy. Deliberately mirrors WalletService: the balance is
 * always derived by summing the ledger, never stored — same discipline this
 * codebase already applies to wallet balances, live VAT and Product's margin
 * accessors.
 *
 * Points are NOT money. Nothing here touches the wallet; the only bridge is
 * PointConversionService.
 */
class PlugPointService
{
    public function balance(int $affiliateId): int
    {
        return (int) PlugPointTransaction::where('affiliate_id', $affiliateId)->sum('points');
    }

    /**
     * Total ever earned, ignoring conversions spent — the "lifetime points"
     * figure an affiliate recognises, which a plain balance can't show once
     * they start converting.
     */
    public function lifetimeEarned(int $affiliateId): int
    {
        return (int) PlugPointTransaction::where('affiliate_id', $affiliateId)
            ->where('points', '>', 0)
            ->sum('points');
    }

    /**
     * Credits points for an approved submission. Idempotent by construction:
     * one submission can hold at most one credit row per source, so a repeated
     * approval finds the row already there and writes nothing.
     *
     * Callers must already hold the submission row lock (see
     * AffiliateTaskService / DailySocialShareService) — this guards against a
     * retry, not against a race.
     */
    public function creditForSubmission(
        AffiliateTaskSubmission $submission,
        int $points,
        string $source,
        string $description,
    ): ?PlugPointTransaction {
        if ($points <= 0) {
            return null;
        }

        $alreadyCredited = PlugPointTransaction::where('affiliate_task_submission_id', $submission->id)
            ->where('source', $source)
            ->exists();

        if ($alreadyCredited) {
            return null;
        }

        return $submission->plugPointTransactions()->create([
            'affiliate_id' => $submission->affiliate_id,
            'type'         => 'credit',
            'points'       => $points,
            'source'       => $source,
            'description'  => $description,
        ]);
    }

    /**
     * Admin correction. Kept separate from the submission-scoped credit above
     * because it has no source row to be idempotent against — every call is a
     * deliberate new ledger entry.
     */
    public function adjust(Affiliate $affiliate, int $points, string $description): PlugPointTransaction
    {
        return $affiliate->plugPointTransactions()->create([
            'type'        => $points >= 0 ? 'credit' : 'debit',
            'points'      => $points,
            'source'      => 'adjustment',
            'description' => $description,
        ]);
    }
}
