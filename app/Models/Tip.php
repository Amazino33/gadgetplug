<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Engagement\FeatureRegistry;
use App\Support\Engagement\Features\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An in-app tip or a "What's new" announcement, written by a platform admin.
 *
 * Who sees it, and when, is decided by App\Support\Engagement\TipEligibility —
 * nowhere else. This model only describes the tip.
 */
class Tip extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    public const TYPE_TIP = 'tip';

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STYLE_SHEET = 'sheet';

    public const STYLE_MODAL = 'modal';

    public const FREQUENCY_ONCE = 'once';

    public const FREQUENCY_TIMES = 'times';

    public const FREQUENCY_UNTIL_CLICKED = 'until_clicked';

    /** Pseudo-role for targeting: the vendor's owner holds no Spatie role. */
    public const ROLE_OWNER = 'owner';

    public const IMAGE_COLLECTION = 'image';

    public const VIDEO_COLLECTION = 'video';

    public const POSTER_COLLECTION = 'poster';

    protected $guarded = [];

    protected $casts = [
        'button_params' => 'array',
        'panels' => 'array',
        'roles' => 'array',
        'show_not_now' => 'boolean',
        'hide_if_feature_used' => 'boolean',
        'show_on_form_pages' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'priority' => 'integer',
        'delay_seconds' => 'integer',
        'max_views' => 'integer',
        'cooldown_days' => 'integer',
        'adoption_window_days' => 'integer',
        'condition_value' => 'integer',
        'moment_threshold' => 'integer',
    ];

    /**
     * Mirrors the column defaults, so a tip created in code behaves the same
     * before and after a refresh() (Eloquent does not read DB defaults back).
     */
    protected $attributes = [
        'type' => self::TYPE_TIP,
        'panels' => '["vendor"]',
        'condition' => 'always',
        'hide_if_feature_used' => true,
        'show_not_now' => true,
        'show_on_form_pages' => false,
        'status' => self::STATUS_DRAFT,
        'priority' => 0,
        'delay_seconds' => 4,
        'frequency' => self::FREQUENCY_ONCE,
    ];

    protected static function booted(): void
    {
        // Announcements are shown once per person, full stop, and default to
        // the centre modal. Enforced here so no form or seeder can get it wrong.
        static::saving(function (self $tip): void {
            // Bottom sheet / corner card for tips, centre modal for announcements,
            // unless the admin chose otherwise.
            $tip->display_style ??= $tip->isAnnouncement() ? self::STYLE_MODAL : self::STYLE_SHEET;

            if ($tip->isAnnouncement()) {
                $tip->frequency = self::FREQUENCY_ONCE;
                $tip->moment = null;
            }
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection(self::VIDEO_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['video/mp4', 'image/gif']);

        $this->addMediaCollection(self::POSTER_COLLECTION)
            ->useDisk('public')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TipEvent::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active and inside its date window. Panel, role and the rest are TipEligibility's job. */
    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isAnnouncement(): bool
    {
        return $this->type === self::TYPE_ANNOUNCEMENT;
    }

    public function isMomentTip(): bool
    {
        return ! $this->isAnnouncement() && filled($this->moment);
    }

    public function feature(): ?Feature
    {
        return $this->feature_key ? app(FeatureRegistry::class)->find($this->feature_key) : null;
    }

    /**
     * How many times one person may be shown this tip. Null is unlimited
     * (until clicked or dismissed).
     */
    public function viewLimit(): ?int
    {
        return match ($this->frequency) {
            self::FREQUENCY_TIMES => max(1, (int) $this->max_views),
            self::FREQUENCY_UNTIL_CLICKED => null,
            default => 1,
        };
    }

    public function effectiveCooldownDays(): int
    {
        return $this->cooldown_days ?? TipSetting::cooldownDays();
    }

    public function effectiveAdoptionWindowDays(): int
    {
        return $this->adoption_window_days ?: TipSetting::adoptionWindowDays();
    }
}
