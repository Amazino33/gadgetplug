<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A like or a share on a feed post.
 *
 * Saves are not here — they are the wishlists table, which is already
 * login-gated and already has a page reading it. Two tables claiming "saved"
 * would mean two answers to the same question.
 */
class ProductInteraction extends Model
{
    protected $guarded = [];

    /** Presence is the state: the row exists, or the product is not liked. */
    public const TYPE_LIKE = 'like';

    /** An event, not a state. Appended, never toggled off. */
    public const TYPE_SHARE = 'share';

    public const TYPES = [self::TYPE_LIKE, self::TYPE_SHARE];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLikes(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_LIKE);
    }

    public function scopeShares(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SHARE);
    }

    /**
     * Whoever is doing the interacting: a signed-in user, or a device.
     *
     * Never both. A guest's rows carry only a device token, and the login
     * listener reassigns them to the user rather than leaving a row that two
     * identities could each claim.
     */
    public function scopeFor(Builder $query, ?int $userId, ?string $deviceToken): Builder
    {
        return $userId
            ? $query->where('user_id', $userId)
            : $query->whereNull('user_id')->where('device_token', $deviceToken);
    }
}
