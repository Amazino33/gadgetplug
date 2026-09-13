<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\UserTourProgress;
use App\Models\Vendor;
use App\Support\Tours\TourRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The vendor-facing help centre: categories, articles, search, and the tours.
 *
 * One page rather than three. Category list, article list and article body are
 * the same screen in three states, driven by query parameters, which is what
 * makes every one of them a link an admin can paste into a support reply and a
 * "?" button can deep-link to.
 *
 * Nothing here is tenant-scoped. Help articles are documentation for the
 * product, shared by every store, so the queries below deliberately go straight
 * at the models with no vendor filter.
 */
class HelpCenter extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Help & Guides';

    protected static ?string $title = 'Help & Guides';

    protected static ?string $slug = 'help';

    // Ungrouped, so it sits under the Dashboard and is reachable without first
    // working out which section it would have been filed under. Someone looking
    // for help does not know the answer's category yet -- that is the problem.
    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.vendor.pages.help-center';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'category')]
    public ?string $categorySlug = null;

    #[Url(as: 'article')]
    public ?string $articleSlug = null;

    /** Help is for everyone who can reach the panel; gating it defeats its purpose. */
    public static function canAccess(): bool
    {
        return filament()->getTenant() !== null;
    }

    /** Searching from inside a category searches everything, not just that shelf. */
    public function updatedSearch(): void
    {
        $this->articleSlug = null;
        $this->categorySlug = null;
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    /** The article being read, or null when showing a list. */
    public function getArticle(): ?HelpArticle
    {
        if (blank($this->articleSlug)) {
            return null;
        }

        return HelpArticle::query()
            ->published()
            ->with('category')
            ->where('slug', $this->articleSlug)
            ->first();
    }

    public function getCategory(): ?HelpCategory
    {
        if (blank($this->categorySlug)) {
            return null;
        }

        return HelpCategory::query()->published()->where('slug', $this->categorySlug)->first();
    }

    /**
     * Categories with a count of what is actually readable inside them.
     *
     * withCount carries the same published filter as the list itself, so a
     * category cannot advertise four guides and then open onto one.
     *
     * @return Collection<int, HelpCategory>
     */
    public function getCategories(): Collection
    {
        return HelpCategory::query()
            ->published()
            ->withCount(['articles as published_articles_count' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (HelpCategory $c): bool => $c->published_articles_count > 0)
            ->values();
    }

    /**
     * The articles for the current view: search hits, one category, or nothing.
     *
     * Only the columns the list renders are selected. The body is the largest
     * column in the table and holds every screenshot's markup; loading twenty
     * of them to print twenty titles is the sort of thing that makes a page
     * expensive on a phone.
     *
     * @return Collection<int, HelpArticle>
     */
    public function getArticles(): Collection
    {
        $query = HelpArticle::query()
            ->published()
            ->select(['id', 'help_category_id', 'title', 'slug', 'excerpt', 'tour_key', 'sort_order'])
            ->with('category:id,name,slug');

        if ($this->isSearching()) {
            return $query->search($this->search)->orderBy('sort_order')->limit(50)->get();
        }

        $category = $this->getCategory();

        if (! $category) {
            return collect();
        }

        return $query
            ->where('help_category_id', $category->id)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Every tour, with whether this person has already seen it.
     *
     * Listed here so a tour waved away months ago is never lost -- the help
     * centre is the one place it can always be started again.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTours(): array
    {
        $vendor = filament()->getTenant();
        $seen = $vendor
            ? UserTourProgress::seenKeys((int) auth()->id(), (int) $vendor->id)
            : [];

        return collect(TourRegistry::forVendorSlug((string) $vendor?->slug))
            ->map(fn (array $tour): array => [
                ...$tour,
                'seen' => in_array($tour['key'], $seen, true),
            ])
            ->values()
            ->all();
    }

    /** The tour attached to the article being read, if any. */
    public function getArticleTour(): ?array
    {
        $article = $this->getArticle();

        if (! $article?->tour_key) {
            return null;
        }

        $vendor = filament()->getTenant();

        return TourRegistry::forVendorSlug((string) $vendor?->slug)[$article->tour_key] ?? null;
    }

    // ── Deep-linking from elsewhere in the panel ─────────────────────────────

    /**
     * Whether a given guide exists and is published.
     *
     * Cached for the request: three "?" buttons on one page would otherwise be
     * three identical queries, and this runs on pages vendors hit constantly.
     *
     * @var array<string, bool>
     */
    protected static array $existsCache = [];

    public static function articleExists(string $slug): bool
    {
        return static::$existsCache[$slug] ??= HelpArticle::query()
            ->published()
            ->where('slug', $slug)
            ->exists();
    }

    public static function articleUrl(string $slug): string
    {
        return static::getUrl(['article' => $slug]);
    }

    /**
     * The contextual "?" button.
     *
     * Add one to any page's header actions with the slug of the guide that
     * answers the question that page raises:
     *
     *     HelpCenter::helpAction('how-to-record-a-procurement')
     *
     * It shows itself only when that guide has actually been written, so a slug
     * can be wired up before the article exists without ever pointing a vendor
     * at an empty page.
     */
    public static function helpAction(string $slug, string $label = 'How do I do this?'): Action
    {
        return Action::make('help_'.str_replace('-', '_', $slug))
            ->label($label)
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->url(fn (): string => static::articleUrl($slug))
            ->visible(fn (): bool => static::articleExists($slug));
    }

    /**
     * The "Take a tour" button.
     *
     * Add it to a page's header actions alongside helpAction(); it launches the
     * tour in place if a chapter belongs to this page, and otherwise sends the
     * vendor to where the tour starts. Hidden if the key is not in the registry,
     * so a renamed tour degrades to a missing button rather than a dead one.
     */
    public static function tourAction(string $tourKey, string $label = 'Take a tour'): Action
    {
        return Action::make('tour_'.str_replace('-', '_', $tourKey))
            ->label($label)
            ->icon('heroicon-o-play')
            ->color('gray')
            ->extraAttributes([
                'x-on:click' => 'window.gpTours?.start('.json_encode($tourKey).')',
            ])
            ->visible(fn (): bool => TourRegistry::has($tourKey));
    }

    /**
     * A vendor-panel URL for an article, for the admin's "View as a vendor"
     * button. Any vendor will do: the content is identical for all of them.
     */
    public static function previewUrlFor(HelpArticle $article): string
    {
        $vendor = Vendor::query()->orderBy('id')->first();

        if (! $vendor) {
            return '';
        }

        return static::getUrl(['article' => $article->slug], tenant: $vendor, panel: 'vendor');
    }
}
