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
        'resolved_at'     => 'datetime',
    ];

    /** Handed over, not yet acknowledged by the person named as receiving it. */
    public const STATUS_PENDING = 'pending';

    /** The receiver says they got this. Both names are now on it. */
    public const STATUS_CONFIRMED = 'confirmed';

    /** The receiver says otherwise. The money is contested, not settled. */
    public const STATUS_DISPUTED = 'disputed';

    /** The disagreement was talked through and somebody recorded the outcome. */
    public const STATUS_RESOLVED = 'resolved';

    /** The claimed amount was accepted after all — the submitter was right. */
    public const OUTCOME_ACCEPTED = 'accepted';

    /** The difference is the submitter's to answer for, and now sits on them. */
    public const OUTCOME_CHARGED = 'charged';

    /** The business absorbs the difference; nobody is asked to pay it. */
    public const OUTCOME_WRITTEN_OFF = 'written_off';

    /**
     * What this handover actually put into the business's hands.
     *
     * Claimed while nobody disagrees; the receiver's figure once a dispute has
     * been settled against the submitter. Written as SQL rather than PHP so the
     * drawer balance stays one query — see scopeAgainstBalance.
     */
    public const EFFECTIVE_AMOUNT_SQL = "
        CASE
            WHEN status IN ('pending', 'confirmed') THEN amount
            WHEN status = 'resolved' AND resolution_outcome = 'accepted' THEN amount
            WHEN status = 'resolved' THEN COALESCE(disputed_amount, 0)
            ELSE 0
        END
    ";

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

    public function isDisputed(): bool
    {
        return $this->status === self::STATUS_DISPUTED;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /** What the two parties disagree about, as a positive figure. */
    public function disputedGap(): float
    {
        return round((float) $this->amount - (float) ($this->disputed_amount ?? 0), 2);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            // A settled dispute counts again, but only for what was agreed to
            // have arrived. While it is still contested it counts for nothing,
            // so denying receipt can never be the easy way to clear a balance.
            self::STATUS_RESOLVED,
        ]);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }
}
