<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateCommissionItem;
use App\Models\AffiliateLevel;
use App\Models\AffiliateTaskSubmission;
use Illuminate\Support\Carbon;

// Progression is on lifetime CLEARED sales value (AffiliateCommissionItem.base_amount,
// summed only across commissions that survived the hold — status 'available'),
// plus (Prompt 3) any approved task submissions flagged to count toward level
// progress. Both are pure derived queries and can safely go up or down (e.g. a
// post-hoc rejection removes an order's commission from 'available', pulling
// the sum back down) — the current LEVEL, in contrast, is a stored,
// ratchet-only field, exactly like WalletService derives balances but never
// re-derives a level downward.
class AffiliateLevelProgressionService
{
    public function lifetimeSalesValue(int $affiliateId): float
    {
        return (float) AffiliateCommissionItem::whereHas('commission', function ($query) use ($affiliateId) {
            $query->where('affiliate_id', $affiliateId)->where('status', 'available');
        })->sum('base_amount');
    }

    public function taskProgressValue(int $affiliateId): float
    {
        return (float) AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->where('status', 'approved')
            ->sum('level_progress_value');
    }

    public function totalProgressValue(int $affiliateId): float
    {
        return $this->lifetimeSalesValue($affiliateId) + $this->taskProgressValue($affiliateId);
    }

    // Last time this affiliate did anything that should be read as "still
    // engaged" for the inactivity-demotion clock — a cleared sale, or an
    // approved task completion. Renamed from lastQualifyingSaleAt (Prompt 2)
    // now that tasks are a second source of qualifying activity: an
    // affiliate who only completes tasks and never sells anything must not
    // be demoted purely for having zero sales while actively engaged.
    public function lastQualifyingActivityAt(int $affiliateId): ?Carbon
    {
        $lastSale = AffiliateCommission::where('affiliate_id', $affiliateId)
            ->where('status', 'available')
            ->max('available_at');

        $lastTask = AffiliateTaskSubmission::where('affiliate_id', $affiliateId)
            ->where('status', 'approved')
            ->max('reviewed_at');

        $latest = collect([$lastSale, $lastTask])->filter()->max();

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * Promotes the affiliate to the highest level their total progress value
     * (sales + task contributions) now qualifies for. Never demotes — a
     * lower recomputed value than before (e.g. after a rejection) simply
     * leaves the current level untouched.
     */
    public function recompute(Affiliate $affiliate): void
    {
        $totalValue = $this->totalProgressValue($affiliate->id);

        $qualifyingLevel = AffiliateLevel::where('is_active', true)
            ->where('target', '<=', $totalValue)
            ->orderByDesc('sort_order')
            ->first();

        if (! $qualifyingLevel) {
            return;
        }

        $currentSortOrder = $affiliate->level?->sort_order ?? -1;

        if ($qualifyingLevel->sort_order > $currentSortOrder) {
            $affiliate->update([
                'affiliate_level_id' => $qualifyingLevel->id,
                'level_achieved_at'  => now(),
            ]);
        }
    }
}
