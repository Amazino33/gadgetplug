<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What happened to a tip (or a Learn-page feature) for one person.
 *
 * Append-only. Every piece of per-user state — seen, retired, cooling down,
 * today's pop-up used, latest thumbs answer — is derived from these rows, so
 * there is no counter anywhere that can drift from what actually happened.
 * Changing a thumbs answer is a new row; the latest one counts.
 */
class TipEvent extends Model
{
    public const SHOWN = 'shown';

    public const CLICKED = 'clicked';

    /** "Not now" or closed: counts as seen and starts the cooldown. */
    public const SNOOZED = 'snoozed';

    /** "Don't show again": retires the tip for this person. */
    public const DISMISSED = 'dismissed';

    public const HELPFUL_YES = 'helpful_yes';

    public const HELPFUL_NO = 'helpful_no';

    public const VIDEO_PLAYED = 'video_played';

    public const EVENTS = [
        self::SHOWN, self::CLICKED, self::SNOOZED, self::DISMISSED,
        self::HELPFUL_YES, self::HELPFUL_NO, self::VIDEO_PLAYED,
    ];

    /** Events after which a tip never comes back for that person. */
    public const RETIRING = [self::CLICKED, self::DISMISSED];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! in_array($event->event, self::EVENTS, true)) {
                throw new LogicException("Unknown tip event [{$event->event}].");
            }

            if ($event->tip_id === null && blank($event->feature_key)) {
                throw new LogicException('A tip event must belong to a tip or a feature.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Tip events are append-only and can never be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Tip events are append-only and can never be deleted.');
        });
    }

    public function tip(): BelongsTo
    {
        return $this->belongsTo(Tip::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
