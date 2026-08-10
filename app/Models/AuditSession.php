<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSession extends Model
{
    protected $fillable = [
        'vendor_id',
        'product_id',
        'system_quantity',
        'storekeeper_a_id',
        'count_a',
        'storekeeper_b_id',
        'count_b',
        'manager_id',
        'manager_override_count',
        'status',
        'reason_code',
        'loss_value',
    ];

    protected $casts = [
        'loss_value'      => 'decimal:2',
        'system_quantity' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function storekeeperA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_a_id');
    }

    public function storekeeperB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_b_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // ---- Helpers for our logic ----
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function hasDiscrepancy(): bool
    {
        return $this->status === 'discrepancy';
    }

    /**
     * The figure everyone has settled on. A manager override supersedes both
     * counts; otherwise B and A agree (that is what makes a row 'verified').
     */
    public function countedQuantity(): int
    {
        return (int) ($this->manager_override_count ?? $this->count_b ?? $this->count_a ?? 0);
    }

    /**
     * Counted minus what the system expected *at the time of the count*.
     *
     * Null when no baseline was recorded — rows created before the
     * system_quantity column exists genuinely cannot be measured, and returning
     * 0 there would read as "no discrepancy" rather than "unknown".
     *
     * Note this is not the same number as the stock correction applied on
     * resolve: that one is measured against live stock, because its job is to
     * make the shelf figure right today. This one is the audit fact.
     */
    public function countedVariance(): ?int
    {
        if ($this->system_quantity === null) {
            return null;
        }

        return $this->countedQuantity() - (int) $this->system_quantity;
    }

    /** A count is only attributable once both parties (or the manager) have settled it. */
    public function isSettled(): bool
    {
        return in_array($this->status, ['verified', 'resolved_by_override'], true);
    }
}
