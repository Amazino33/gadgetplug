<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\TipEvent;

/**
 * "Was this helpful?" counts. One answer per person: a changed answer is a new
 * event and the latest one counts, so the history stays in tip_events.
 */
final class TipFeedback
{
    /** @return array{yes: int, no: int, percent: int|null, plays: int} */
    public static function forTip(int $tipId): array
    {
        return self::summarise(TipEvent::query()->where('tip_id', $tipId));
    }

    /** @return array{yes: int, no: int, percent: int|null, plays: int} */
    public static function forFeature(string $featureKey): array
    {
        return self::summarise(TipEvent::query()->whereNull('tip_id')->where('feature_key', $featureKey));
    }

    /** This person's current answer at this vendor: 'yes', 'no' or null. */
    public static function answerOf(int $userId, ?int $vendorId, ?int $tipId, ?string $featureKey = null): ?string
    {
        $event = TipEvent::query()
            ->where('user_id', $userId)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId), fn ($q) => $q->whereNull('vendor_id'))
            ->when($tipId, fn ($q) => $q->where('tip_id', $tipId), fn ($q) => $q->whereNull('tip_id')->where('feature_key', $featureKey))
            ->whereIn('event', [TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])
            ->latest('id')
            ->value('event');

        return match ($event) {
            TipEvent::HELPFUL_YES => 'yes',
            TipEvent::HELPFUL_NO => 'no',
            default => null,
        };
    }

    /** @return array{yes: int, no: int, percent: int|null, plays: int} */
    private static function summarise($query): array
    {
        $answers = (clone $query)
            ->whereIn('event', [TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])
            ->orderBy('id')
            ->get(['user_id', 'vendor_id', 'event'])
            // Later rows overwrite earlier ones: the latest answer counts.
            ->keyBy(fn (TipEvent $e) => $e->user_id.':'.$e->vendor_id);

        $yes = $answers->where('event', TipEvent::HELPFUL_YES)->count();
        $no = $answers->where('event', TipEvent::HELPFUL_NO)->count();

        return [
            'yes' => $yes,
            'no' => $no,
            'percent' => ($yes + $no) > 0 ? (int) round(100 * $yes / ($yes + $no)) : null,
            'plays' => (clone $query)->where('event', TipEvent::VIDEO_PLAYED)->count(),
        ];
    }
}
