<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash handed from whoever took it to whoever is responsible for it next.
 *
 * The amount never changes once submitted. A handover the receiver disagrees
 * with is disputed, not corrected — what each person said at the time is the
 * record, and reconciling it is a conversation between two named people rather
 * than an edit.
 */
class CashSubmission extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'          => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'disputed_amount' => 'decimal:2',
        'confirmed_at'    => 'datetime',
        'disputed_at'     => 'datetime',
    ];

    /** Handed over, not yet acknowledged by the person named as receiving it. */
    public const STATUS_PENDING = 'pending';

    /** The receiver says they got this. Both names are now on it. */
    public const STATUS_CONFIRMED = 'confirmed';

    /** The receiver says otherwise. The money is contested, not settled. */
    public const STATUS_DISPUTED = 'disputed';

    protected static function booted(): void
    {
        static::created(function (self $submission) {
            $submission->updateQuietly([
                'reference' => 'GP-CASH-'.str_pad((string) $submission->id, 5, '0', STR_PAD_LEFT),
            ]);
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

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * What the handover was short by, or over by if positive.
     *
     * Derived from two columns that never change, so it cannot drift from them.
     */
    public function variance(): float
    {
        return round((float) $this->amount - (float) $this->expected_amount, 2);
    }

    public function isShort(): bool
    {
        return $this->variance() < 0;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Handovers that have left the submitter's hands.
     *
     * A disputed one is deliberately absent: if the receiver says it never
     * arrived, the money is still the submitter's to account for, and letting a
     * dispute reduce their balance would make denying receipt the easiest way
     * to clear it.
     */
    public function scopeAgainstBalance(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFIRMED]);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }
}
