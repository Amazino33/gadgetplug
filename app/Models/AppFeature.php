<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\Features\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * What users read about a feature: title, short description, video/GIF.
 *
 * One row per class in FeatureRegistry, created by `php artisan features:sync`.
 * The class decides what the feature is and how use is detected; this row is
 * the admin-editable copy. Sync never overwrites copy an admin has edited.
 */
class AppFeature extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const VIDEO_COLLECTION = 'video';

    public const POSTER_COLLECTION = 'poster';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
        'is_registered' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::VIDEO_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['video/mp4', 'image/gif']);

        $this->addMediaCollection(self::POSTER_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    /** Listed on the Learn page: visible, and still backed by a registry class. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_visible', true)->where('is_registered', true);
    }

    /** The code side of this feature, or null if its class has been removed. */
    public function definition(): ?Feature
    {
        return app(FeatureRegistry::class)->find($this->key);
    }
}
