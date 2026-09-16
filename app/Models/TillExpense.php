<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Cash that left the till for something, declared when it left.
 *
 * Append-only, like every other row in this codebase that stands between a
 * named person and an accusation of being short. One that could be edited later
 * would let a shortage be backfilled with an explanation after the fact, which
 * is the entire thing it is meant to rule out.
 */
class TillExpense extends Model
{
    protected $guarded = [];

    // Nothing updates these, so an updated_at would only ever echo created_at.
    public const UPDATED_AT = null;

    protected $casts = [
        'amount'     => 'decimal:2',
        'spent_at'   => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $expense) {
            if ((float) $expense->amount <= 0) {
                throw new LogicException('A till expense has to be for some money.');
            }

            if (blank($expense->reason)) {
                throw new LogicException('An expense with no stated reason is just a shortage.');
            }
        });

        static::updating(function () {
            throw new LogicException('Till expenses are append-only. Log a correcting entry instead of editing one.');
        });

        static::deleting(function () {
            throw new LogicException('Till expenses are append-only — deleting one turns declared spending back into an unexplained shortage.');
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /** Spent within a window, by when the money left rather than when it was typed in. */
    public function scopeSpentBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('spent_at', [$from, $to]);
    }
}
