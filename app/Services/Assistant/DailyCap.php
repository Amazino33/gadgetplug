<?php

namespace App\Services\Assistant;

use App\Models\Assistant\AssistantQuestion;
use App\Models\Vendor;
use Carbon\CarbonImmutable;

/**
 * How many questions a vendor may ask per day, and how many are left.
 *
 * "Day" is the business's day: it resets at midnight Africa/Lagos, the same
 * boundary "today" uses everywhere else. Counted from the question log rather
 * than a counter column, so the figure the admin sees and the figure enforced
 * can never drift apart.
 */
final class DailyCap
{
    public function limitFor(Vendor $vendor): int
    {
        return (int) ($vendor->assistant_daily_cap ?? config('assistant.daily_question_cap', 100));
    }

    public function usedToday(Vendor $vendor): int
    {
        return AssistantQuestion::query()
            ->where('vendor_id', $vendor->id)
            ->where('counts_toward_cap', true)
            ->where('created_at', '>=', $this->dayStart()->utc())
            ->count();
    }

    public function reached(Vendor $vendor): bool
    {
        return $this->usedToday($vendor) >= $this->limitFor($vendor);
    }

    public function resetsAt(): CarbonImmutable
    {
        return $this->dayStart()->addDay();
    }

    public function limitMessage(Vendor $vendor): string
    {
        $resets = $this->resetsAt();
        $hours = max(1, (int) ceil(abs(CarbonImmutable::now($this->timezone())->diffInMinutes($resets)) / 60));

        return sprintf(
            "Your business has used all %d of today's assistant questions. It will be available again at midnight (%s), in about %d %s.",
            $this->limitFor($vendor),
            $resets->format('D j M'),
            $hours,
            $hours === 1 ? 'hour' : 'hours',
        );
    }

    private function dayStart(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    private function timezone(): string
    {
        return (string) config('reporting.timezone', 'Africa/Lagos');
    }
}
