<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\TipEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Engagement\FeatureUsage;
use App\Support\Engagement\LearnCatalogue;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Renderless;

/**
 * Learn GadgetPlug: every feature this person can open, with a short
 * explanation, a video, "Show me", a tick once used, progress, and What's new.
 *
 * All the deciding lives in LearnCatalogue (and through it the feature
 * registry and TipEligibility); this page only shows it and records what
 * people do here as tip_events.
 */
class LearnGadgetPlug extends Page
{
    public const PERMISSION = 'view_learn_page';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Learn GadgetPlug';

    protected static ?string $title = 'Learn GadgetPlug';

    protected static ?string $slug = 'learn';

    // Ungrouped, beside Help & Guides (100), for the same reason it is: someone
    // looking for what the app can do doesn't know which section to open.
    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.vendor.pages.learn';

    /** @var array<string, true> media already logged as played on this visit */
    public array $played = [];

    /**
     * Its own permission like every page (on every default role, so a vendor
     * can take it away on the Roles screen). Not part of the free plan, whose
     * shops can only open Products and Shop Profile.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        $vendor = filament()->getTenant();

        if (! $user instanceof User || ! $vendor instanceof Vendor) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return ! $vendor->isFreePlan()
            && ($vendor->isOwner($user) || $user->hasVendorPermission($vendor->id, self::PERMISSION));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected function getViewData(): array
    {
        [$user, $vendor] = $this->who();
        $catalogue = app(LearnCatalogue::class);
        $announcements = $catalogue->announcements($user, $vendor);

        return [
            'features' => $catalogue->features($user, $vendor),
            'progress' => $catalogue->progress($user, $vendor),
            'announcements' => $announcements,
            'unread' => count(array_filter($announcements, fn (array $a): bool => ! $a['read'])),
            'judgedOnBusiness' => app(FeatureUsage::class)->isOwnerOrAdmin($user, $vendor),
            'vendorName' => $vendor->name,
        ];
    }

    // ── Features ─────────────────────────────────────────────────────────────

    /**
     * "Show me": recorded as a tap, then off to the feature's page, or the
     * name of a browser event for features that open in place (Ask
     * GadgetPlug opens the chat bubble).
     */
    public function showMe(string $key): ?string
    {
        [$user, $vendor] = $this->who();
        $feature = app(LearnCatalogue::class)->feature($user, $vendor, $key);

        if (! $feature) {
            return null;
        }

        app(LearnCatalogue::class)->record($user, $vendor, TipEvent::CLICKED, featureKey: $key);

        if ($feature['event']) {
            return $feature['event'];
        }

        if ($feature['url']) {
            $this->redirect($feature['url']);
        }

        return null;
    }

    #[Renderless]
    public function rateFeature(string $key, string $answer): void
    {
        [$user, $vendor] = $this->who();

        if (! in_array($answer, ['yes', 'no'], true) || ! app(LearnCatalogue::class)->feature($user, $vendor, $key)) {
            return;
        }

        app(LearnCatalogue::class)->record($user, $vendor, $answer === 'yes' ? TipEvent::HELPFUL_YES : TipEvent::HELPFUL_NO, featureKey: $key);
    }

    #[Renderless]
    public function featurePlayed(string $key): void
    {
        [$user, $vendor] = $this->who();

        if (isset($this->played['f:'.$key]) || ! app(LearnCatalogue::class)->feature($user, $vendor, $key)) {
            return;
        }

        $this->played['f:'.$key] = true;
        app(LearnCatalogue::class)->record($user, $vendor, TipEvent::VIDEO_PLAYED, featureKey: $key);
    }

    // ── What's new ───────────────────────────────────────────────────────────

    /**
     * Read on this page: a shown event on the Learn route. That clears the
     * dot and retires the announcement's pop-up, but never counts towards the
     * day's pop-up cap (see TipEligibility::shownToday()).
     */
    #[Renderless]
    public function markRead(int $tipId): void
    {
        $item = $this->announcement($tipId);

        if ($item && ! $item['read']) {
            [$user, $vendor] = $this->who();
            app(LearnCatalogue::class)->record($user, $vendor, TipEvent::SHOWN, tipId: $tipId);
        }
    }

    public function openAnnouncement(int $tipId): ?string
    {
        $item = $this->announcement($tipId);

        if (! $item || ! $item['card']['hasButton']) {
            return null;
        }

        [$user, $vendor] = $this->who();
        app(LearnCatalogue::class)->record($user, $vendor, TipEvent::CLICKED, tipId: $tipId);

        if ($item['event']) {
            return $item['event'];
        }

        if ($item['url']) {
            $this->redirect($item['url']);
        }

        return null;
    }

    #[Renderless]
    public function rateAnnouncement(int $tipId, string $answer): void
    {
        if (! in_array($answer, ['yes', 'no'], true) || ! $this->announcement($tipId)) {
            return;
        }

        [$user, $vendor] = $this->who();
        app(LearnCatalogue::class)->record($user, $vendor, $answer === 'yes' ? TipEvent::HELPFUL_YES : TipEvent::HELPFUL_NO, tipId: $tipId);
    }

    #[Renderless]
    public function announcementPlayed(int $tipId): void
    {
        if (isset($this->played['t:'.$tipId]) || ! $this->announcement($tipId)) {
            return;
        }

        $this->played['t:'.$tipId] = true;
        [$user, $vendor] = $this->who();
        app(LearnCatalogue::class)->record($user, $vendor, TipEvent::VIDEO_PLAYED, tipId: $tipId);
    }

    /** The announcement, only if it was sent to this person. */
    private function announcement(int $tipId): ?array
    {
        [$user, $vendor] = $this->who();

        foreach (app(LearnCatalogue::class)->announcements($user, $vendor) as $item) {
            if ($item['tip']->id === $tipId) {
                return $item;
            }
        }

        return null;
    }

    /** @return array{0: User, 1: Vendor} */
    private function who(): array
    {
        /** @var User $user */
        $user = auth()->user();
        /** @var Vendor $vendor */
        $vendor = filament()->getTenant();

        return [$user, $vendor];
    }
}
