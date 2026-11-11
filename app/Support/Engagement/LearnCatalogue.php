<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Filament\Vendor\Pages\HelpCenter;
use App\Models\AppFeature;
use App\Models\HelpArticle;
use App\Models\Tip;
use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * What the Learn GadgetPlug page (and its dashboard card) shows one person at
 * one business.
 *
 * Nothing here decides anything new. Which features are listed comes from each
 * feature's own canAccess(); the ticks come from FeatureUsage (the registry's
 * firstUsedAt, judged per person or per business by the one rule); which
 * announcements are listed comes from TipEligibility's targeting. So the page
 * can never disagree with the tips or the reports.
 */
class LearnCatalogue
{
    /** The Learn page's route. Pop-ups never show on it (see TipEligibility). */
    public const ROUTE = 'filament.vendor.pages.learn';

    /**
     * The feature_key on the dashboard card's "dismissed" event. Not a
     * registry key: nothing that reads features ever looks for it.
     */
    public const CARD_KEY = 'learn_progress_card';

    /** @var array<string, list<array<string, mixed>>> */
    private array $features = [];

    public function __construct(
        private readonly FeatureRegistry $registry,
        private readonly FeatureUsage $usage,
        private readonly TipEligibility $eligibility,
    ) {}

    /**
     * Every visible feature this person can open, in the admin's order.
     *
     * @return list<array{key: string, title: string, description: string, video: array|null, url: string|null, event: string|null, usedAt: CarbonImmutable|null, businessWide: bool, helpUrl: string|null, answer: string|null}>
     */
    public function features(User $user, Vendor $vendor): array
    {
        return $this->features[$user->id.':'.$vendor->id] ??= $this->buildFeatures($user, $vendor);
    }

    /** @return array{used: int, total: int, percent: int} */
    public function progress(User $user, Vendor $vendor): array
    {
        $features = $this->features($user, $vendor);
        $used = count(array_filter($features, fn (array $f): bool => $f['usedAt'] !== null));
        $total = count($features);

        return [
            'used' => $used,
            'total' => $total,
            'percent' => $total > 0 ? (int) floor(100 * $used / $total) : 0,
        ];
    }

    /** One feature from the list, or null if this person isn't shown it. */
    public function feature(User $user, Vendor $vendor, string $key): ?array
    {
        foreach ($this->features($user, $vendor) as $feature) {
            if ($feature['key'] === $key) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * Every announcement that has gone out to this person, newest first, with
     * whether they have seen it. "Seen" means a shown event: as the pop-up, or
     * on the Learn page (see markRead()).
     *
     * Paused, draft and not-yet-started announcements are left out; ones whose
     * end date has passed stay, because this is the history of what changed.
     *
     * @return list<array{tip: Tip, card: array, url: string|null, event: string|null, read: bool, answer: string|null}>
     */
    public function announcements(User $user, Vendor $vendor): array
    {
        $context = $this->context($user, $vendor);

        $tips = Tip::query()
            ->where('type', Tip::TYPE_ANNOUNCEMENT)
            ->where('status', Tip::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->with('media')
            ->orderByRaw('COALESCE(starts_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(fn (Tip $tip): bool => $this->eligibility->targets($tip, $context))
            ->values();

        if ($tips->isEmpty()) {
            return [];
        }

        $events = TipEvent::query()
            ->where('user_id', $user->id)
            ->where('vendor_id', $vendor->id)
            ->whereIn('tip_id', $tips->modelKeys())
            ->whereIn('event', [TipEvent::SHOWN, TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])
            ->orderBy('id')
            ->get(['tip_id', 'event']);

        $read = $events->where('event', TipEvent::SHOWN)->pluck('tip_id')->map(fn ($id) => (int) $id)->flip();
        // Later rows overwrite earlier ones: the latest answer counts.
        $answers = $events->whereIn('event', [TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])
            ->mapWithKeys(fn (TipEvent $e) => [(int) $e->tip_id => $e->event === TipEvent::HELPFUL_YES ? 'yes' : 'no']);

        return $tips->map(function (Tip $tip) use ($context, $read, $answers): array {
            $button = $this->eligibility->button($tip, $context);

            return [
                'tip' => $tip,
                'card' => TipCard::fromTip($tip, filled($tip->button_label) && ($button['url'] !== null || $button['event'] !== null)),
                'url' => $button['url'],
                'event' => $button['event'],
                'read' => $read->has($tip->id),
                'answer' => $answers->get($tip->id),
            ];
        })->all();
    }

    public function unreadCount(User $user, Vendor $vendor): int
    {
        return count(array_filter($this->announcements($user, $vendor), fn (array $a): bool => ! $a['read']));
    }

    /** Has this person closed the dashboard progress card at this business? */
    public function cardDismissed(User $user, Vendor $vendor): bool
    {
        return TipEvent::query()
            ->where('user_id', $user->id)
            ->where('vendor_id', $vendor->id)
            ->whereNull('tip_id')
            ->where('feature_key', self::CARD_KEY)
            ->where('event', TipEvent::DISMISSED)
            ->exists();
    }

    /** Appends one event for this person, at this business and branch. */
    public function record(User $user, Vendor $vendor, string $event, ?int $tipId = null, ?string $featureKey = null): void
    {
        TipEvent::create([
            'tip_id' => $tipId,
            'feature_key' => $featureKey,
            'user_id' => $user->id,
            'vendor_id' => $vendor->id,
            'store_id' => ActiveStore::currentId(),
            'event' => $event,
            'route' => self::ROUTE,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function buildFeatures(User $user, Vendor $vendor): array
    {
        $usedAt = $this->usage->map($user, $vendor);
        $ownerOrAdmin = $this->usage->isOwnerOrAdmin($user, $vendor);

        $rows = AppFeature::query()->listed()->with('media')->orderBy('sort_order')->orderBy('id')->get();
        $articles = $this->publishedArticles($rows->pluck('help_article_slug')->filter()->all());
        $answers = $this->featureAnswers($user, $vendor);
        $features = [];

        foreach ($rows as $row) {
            $feature = $this->registry->find($row->key);

            if (! $feature || ! $this->reachable($feature, $user, $vendor)) {
                continue;
            }

            $event = $feature->clientEvent();
            $slug = $row->help_article_slug;

            $features[] = [
                'key' => $row->key,
                'title' => (string) $row->title,
                'description' => (string) $row->description,
                'video' => TipMedia::video($row, AppFeature::VIDEO_COLLECTION, AppFeature::POSTER_COLLECTION),
                'url' => $event ? null : $feature->destinationUrl($vendor),
                'event' => $event,
                'usedAt' => isset($usedAt[$row->key]) ? CarbonImmutable::parse($usedAt[$row->key]) : null,
                'businessWide' => $feature->isBusinessWideFor($ownerOrAdmin),
                'helpUrl' => $slug && isset($articles[$slug]) ? HelpCenter::getUrl(['article' => $slug], tenant: $vendor) : null,
                'answer' => $answers[$row->key] ?? null,
            ];
        }

        return $features;
    }

    /**
     * This person's current thumbs answer per feature: the latest one counts.
     *
     * @return array<string, 'yes'|'no'>
     */
    private function featureAnswers(User $user, Vendor $vendor): array
    {
        return TipEvent::query()
            ->where('user_id', $user->id)
            ->where('vendor_id', $vendor->id)
            ->whereNull('tip_id')
            ->whereIn('event', [TipEvent::HELPFUL_YES, TipEvent::HELPFUL_NO])
            ->orderBy('id')
            ->get(['feature_key', 'event'])
            ->mapWithKeys(fn (TipEvent $e) => [$e->feature_key => $e->event === TipEvent::HELPFUL_YES ? 'yes' : 'no'])
            ->all();
    }

    private function reachable(Features\Feature $feature, User $user, Vendor $vendor): bool
    {
        try {
            return $feature->canAccess($user, $vendor);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array<string, true> */
    private function publishedArticles(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return HelpArticle::query()->published()->whereIn('slug', $slugs)->pluck('slug')->flip()->map(fn () => true)->all();
    }

    private function context(User $user, Vendor $vendor): TipContext
    {
        return new TipContext(user: $user, vendor: $vendor, panelId: 'vendor', routeName: self::ROUTE, storeId: ActiveStore::currentId());
    }
}
