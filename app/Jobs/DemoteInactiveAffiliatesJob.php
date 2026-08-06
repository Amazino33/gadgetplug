<?php

namespace App\Jobs;

use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Services\Affiliate\AffiliateLevelProgressionService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// Daily. Drops an affiliate exactly one level when they've gone quiet — no
// qualifying activity (a cleared sale or an approved task completion) within
// the configured window. Cascades over
// time (an affiliate inactive for 60 days against a 21-day window keeps
// sliding down), but at most one step per full window: level_achieved_at is
// updated on every demotion too, so a second run the same day (or before
// another full window has elapsed since the last drop) leaves them alone —
// otherwise every run between the first missed sale and the next one would
// re-demote them off the strength of the same stale inactivity fact.
class DemoteInactiveAffiliatesJob implements ShouldQueue
{
    use Queueable;

    public function handle(AffiliateLevelProgressionService $progression): void
    {
        $cutoff = now()->subDays((int) AffiliateSetting::current()->inactivity_demotion_days);
        $lowestSortOrder = AffiliateLevel::where('is_active', true)->min('sort_order');

        if ($lowestSortOrder === null) {
            return;
        }

        Affiliate::whereHas('level', fn ($query) => $query->where('sort_order', '>', $lowestSortOrder))
            ->with('level')
            ->chunkById(100, function ($affiliates) use ($cutoff, $progression) {
                foreach ($affiliates as $affiliate) {
                    $this->demoteIfDue($affiliate, $cutoff, $progression);
                }
            });
    }

    private function demoteIfDue(Affiliate $affiliate, CarbonInterface $cutoff, AffiliateLevelProgressionService $progression): void
    {
        if ($affiliate->level_achieved_at !== null && $affiliate->level_achieved_at->gt($cutoff)) {
            return;
        }

        $lastActivity = $progression->lastQualifyingActivityAt($affiliate->id) ?? $affiliate->created_at;

        if ($lastActivity->gt($cutoff)) {
            return;
        }

        $nextLevel = AffiliateLevel::where('is_active', true)
            ->where('sort_order', '<', $affiliate->level->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if (! $nextLevel) {
            return;
        }

        $previousLevelId = $affiliate->affiliate_level_id;

        $affiliate->update([
            'affiliate_level_id' => $nextLevel->id,
            'level_achieved_at'  => now(),
        ]);

        activity()
            ->performedOn($affiliate)
            ->withProperties(['from' => $previousLevelId, 'to' => $nextLevel->id, 'reason' => 'inactivity'])
            ->log('Affiliate demoted for inactivity');

        Log::info("Affiliate #{$affiliate->id} demoted to level '{$nextLevel->name}' for inactivity.");
    }
}
