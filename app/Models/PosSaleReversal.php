<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Why a completed sale stopped counting.
 *
 * Append-only, like every other accusation in this codebase. The row says a
 * named person withdrew a sale that had already been rung; letting it be edited
 * afterwards would make it worth nothing to the person it names.
 */
class PosSaleReversal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** The whole sale was withdrawn. Nothing of it counts. */
    public const TYPE_VOID = 'void';

    /** Everything came back, so the sale nets to nothing. */
    public const TYPE_RETURN_FULL = 'return_full';

    /** Some of it came back. The rest still counts. */
    public const TYPE_RETURN_PARTIAL = 'return_partial';

    /** What each type leaves on the sale it mirrors. */
    public const STATUS_FOR_TYPE = [
        self::TYPE_VOID           => 'voided',
        self::TYPE_RETURN_FULL    => 'refunded',
        self::TYPE_RETURN_PARTIAL => 'partial_refund',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A reversal is a record of something that happened. Record another one instead of editing it.');
        });

        static::deleting(function () {
            throw new LogicException('Reversals are never deleted — the sale they withdrew would go back to looking untouched.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** The pos_returns row this mirrors, when it came from a return. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isVoid(): bool
    {
        return $this->type === self::TYPE_VOID;
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Reversals recorded within a window.
     *
     * Deliberately by when the reversal happened, not when the sale was rung.
     * A sale voided in October is October's problem to explain, even though the
     * takings it withdraws belong to September — which is precisely the gap a
     * frozen statement is meant to expose rather than absorb.
     */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
