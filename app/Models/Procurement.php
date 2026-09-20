<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Procurement extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'amount_paid', 'approved_by', 'awaiting_user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->vendor_id = $this->vendor_id;
    }
    /** Recorded by the storekeeper, waiting on somebody else to receive it. */
    public const STATUS_PENDING = 'pending';

    /**
     * One side has corrected a line and the other has not answered yet.
     *
     * Deliberately one state rather than the two a first sketch reaches for.
     * "Approver corrected it" and "recorder must re-check it" are the same
     * fact said from opposite ends of the table, and awaiting_user_id already
     * says which end is holding it up.
     */
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    /** Both sides agree. This is the only status under which stock exists. */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'reference', 'vendor_id', 'store_id', 'supplier_id', 'waybill_image',
        'total_cost', 'amount_paid', 'payment_status', 'payment_method', 'status',
        'void_reason', 'notes', 'created_by', 'approved_by', 'approved_at',
        'awaiting_user_id',
    ];

    protected $casts = [
        'total_cost'   => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'approved_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        // Generate reference after insert: GP-PROC-00001
        static::created(function (self $procurement) {
            $procurement->updateQuietly([
                'reference' => 'GP-PROC-' . str_pad($procurement->id, 5, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The branch these goods are being received into. */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementItem::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ProcurementItemCorrection::class);
    }

    /** Whose move it is, while the two sides are still disagreeing. */
    public function awaitingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awaiting_user_id');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(ProcurementLogisticsLeg::class)->orderBy('sort_order');
    }

    // Kept separate from total_cost/recalculate() on purpose — logistics is
    // never folded into product cost or this procurement's total_cost, so it
    // must never feed back into that column's math.
    public function logisticsTotal(): float
    {
        return (float) $this->legs()->sum('amount');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function recalculate(): void
    {
        $total  = $this->items()->selectRaw('SUM(quantity * unit_cost) as total')->value('total') ?? 0;
        $paid   = (float) $this->amount_paid;
        $status = match (true) {
            $paid >= $total && $total > 0 => 'full',
            $paid > 0                     => 'part_payment',
            default                       => 'credit',
        };

        $this->updateQuietly(['total_cost' => $total, 'payment_status' => $status]);
    }

    /**
     * Restate the total at the figures the two sides agreed.
     *
     * Separate from recalculate() rather than folded into it: that method is
     * called while a delivery is being built, when the recorded figures are
     * the only ones there are, and teaching it about corrections would have it
     * quietly reaching for a relation it does not need on the hot path.
     */
    public function recalculateFromVerified(): void
    {
        $this->loadMissing('items.corrections');

        $total  = $this->verifiedTotal();
        $paid   = (float) $this->amount_paid;
        $status = match (true) {
            $paid >= $total && $total > 0 => 'full',
            $paid > 0                     => 'part_payment',
            default                       => 'credit',
        };

        $this->updateQuietly(['total_cost' => $total, 'payment_status' => $status]);
    }

    public function isPending(): bool  { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isVoided(): bool   { return $this->status === self::STATUS_VOIDED; }

    public function isChangesRequested(): bool
    {
        return $this->status === self::STATUS_CHANGES_REQUESTED;
    }

    /** Still being argued about, either untouched or mid-correction. */
    public function isOpen(): bool
    {
        return $this->isPending() || $this->isChangesRequested();
    }

    public function isAwaiting(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $id !== null && (int) $this->awaiting_user_id === (int) $id;
    }

    /**
     * The one approver this batch is tied to, once somebody has engaged with it.
     *
     * Derived from the corrections rather than stored: the first non-recorder
     * to correct a line is the counterparty, and a column saying so again could
     * only ever drift from the rows that prove it. Null while nobody has
     * corrected anything, which is the case where any eligible approver may
     * still pick the batch up.
     */
    public function reviewPartnerId(): ?int
    {
        $id = $this->corrections()
            ->where('corrected_by', '!=', $this->created_by)
            ->orderBy('id')
            ->value('corrected_by');

        return $id === null ? null : (int) $id;
    }

    /**
     * Who spoke last.
     *
     * Not the same question as awaiting_user_id, which is who has to answer.
     * This one exists because a review pass is not a single click: somebody
     * walking a pallet marks line one short, then line three short, and both
     * are the same trip. Without it the second correction would be refused —
     * the batch having already been handed to the other party by the first.
     */
    public function lastCorrectedById(): ?int
    {
        $id = $this->corrections()->reorder('id', 'desc')->value('corrected_by');

        return $id === null ? null : (int) $id;
    }

    /** What the two sides have actually agreed this delivery is worth. */
    public function verifiedTotal(): float
    {
        $this->loadMissing('items.corrections');

        return round(
            $this->items->sum(fn (ProcurementItem $item) => $item->verifiedLineTotal()),
            2,
        );
    }

    public function verifiedQuantity(): int
    {
        $this->loadMissing('items.corrections');

        return (int) $this->items->sum(fn (ProcurementItem $item) => $item->verifiedQuantity());
    }

    public function recordedQuantity(): int
    {
        $this->loadMissing('items');

        return (int) $this->items->sum('quantity');
    }

    public function hasCorrections(): bool
    {
        return $this->corrections()->exists();
    }
}
