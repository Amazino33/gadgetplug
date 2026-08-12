<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Follows AffiliateTaskSubmission's media convention exactly (public disk,
// singleFile, non-queued thumb) rather than inventing a third pattern.
class MarketingMaterial extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('artwork')
            ->useDisk('public')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Contain, 400, 400)
            ->quality(90)
            ->nonQueued();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'caption_template', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The caption this affiliate should post alongside the artwork, with their
     * own link and code substituted in.
     *
     * This is what makes one shared row serve everyone: the material is
     * branded and identical for all affiliates, and the only per-affiliate
     * part — the thing that makes a share attributable and reviewable — is the
     * code/link in the caption. Burning it into the image itself is Prompt 5.
     */
    public function captionFor(Affiliate $affiliate, string $referralLink): string
    {
        $template = $this->caption_template ?: 'Shop GadgetPlug — :link (code :code)';

        return strtr($template, [
            ':link' => $referralLink,
            ':code' => $affiliate->code,
        ]);
    }
}
