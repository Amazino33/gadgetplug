<?php

namespace App\Models;

use App\Support\Tours\TourRegistry;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\SpatieMediaLibraryFileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A step-by-step guide, written by the platform admin and read by every vendor.
 *
 * Not tenant-owned on purpose: see the create_help_categories_table migration.
 */
class HelpArticle extends Model implements HasMedia, HasRichContent
{
    use HasSlug, InteractsWithMedia, InteractsWithRichContent;

    public const MEDIA_COLLECTION = 'help-article-media';

    protected $guarded = [];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(80)
            ->doNotGenerateSlugsOnUpdate();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Screenshots and GIFs dropped into the body by the author.
     *
     * Same convention as every other media model here — public disk, so the
     * picture has a plain cacheable URL. A signed temporary URL (the provider's
     * default) would defeat the browser cache, and re-downloading the same
     * screenshot on every visit is precisely the cost this help centre exists
     * to avoid.
     */
    protected function setUpRichContent(): void
    {
        $this->registerRichContent('body')
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsVisibility('public')
            ->fileAttachmentProvider(
                SpatieMediaLibraryFileAttachmentProvider::make()
                    ->collection(self::MEDIA_COLLECTION),
            );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->useDisk('public');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }

    /**
     * Free-text search over the guides.
     *
     * FULLTEXT where the database has it, LIKE where it does not. The fallback
     * is not a token gesture: the test suite runs on SQLite, so it is the branch
     * the tests actually cover, and it has to return the same articles.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            return $query->whereRaw(
                'MATCH (title, excerpt, body) AGAINST (? IN BOOLEAN MODE)',
                [static::booleanModeTerm($term)],
            );
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('excerpt', 'like', $like)
            ->orWhere('body', 'like', $like));
    }

    /**
     * "record procurement" -> "+record* +procurement*", so a vendor typing half a
     * word still finds the guide. Operator characters are stripped rather than
     * escaped: they have no meaning to someone typing a question into a help box,
     * and left in they turn a search into a syntax error.
     */
    protected static function booleanModeTerm(string $term): string
    {
        $words = preg_split('/\s+/', preg_replace('/[+\-><()~*\"@]+/', ' ', $term)) ?: [];

        $words = array_filter(array_map('trim', $words), fn (string $w) => $w !== '');

        return implode(' ', array_map(fn (string $w) => '+'.$w.'*', $words));
    }

    /**
     * The body as HTML, with every image told to load lazily.
     *
     * Vendors read these on Nigerian mobile data, often on a page holding
     * several screenshots and a GIF. Filament's renderer has no hook for extra
     * image attributes, so they are stamped on afterwards — cheap, and it means
     * an author cannot forget.
     */
    public function renderedBody(): string
    {
        $html = $this->renderRichContent('body');

        if ($html === '') {
            return '';
        }

        return preg_replace('/<img\b(?![^>]*\bloading=)/i', '<img loading="lazy" decoding="async"', $html) ?? $html;
    }

    /** The tour that walks this same flow, if the author linked one. */
    public function tour(): ?array
    {
        return $this->tour_key ? TourRegistry::get($this->tour_key) : null;
    }
}
