<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One party's account of what a procurement line should have said.
 *
 * Never edited, only added to. The storekeeper's 12 and the approver's 10 are
 * both the record; a correction that could be rewritten afterwards would be
 * worth nothing to whichever of the two it ends up contradicting.
 *
 * A counter-correction is simply the next row, so the disagreement reads back
 * in order without a history table of its own.
 */
class ProcurementItemCorrection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'recorded_quantity'  => 'integer',
        'recorded_unit_cost' => 'decimal:2',
        'verified_quantity'  => 'integer',
        'verified_unit_cost' => 'decimal:2',
        'corrected_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A correction is never edited. Record another one instead.');
        });
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class, 'procurement_item_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /** Units gained or lost against what was recorded. Negative means fewer arrived than written down. */
    public function quantityVariance(): int
    {
        return $this->verified_quantity - $this->recorded_quantity;
    }

    /** Naira per unit, against what was recorded. */
    public function unitCostVariance(): float
    {
        return round((float) $this->verified_unit_cost - (float) $this->recorded_unit_cost, 2);
    }

    /**
     * What the correction does to this line's money.
     *
     * Computed from the two line totals rather than by adding the two variances
     * above — quantity and unit cost multiply, so summing their separate
     * variances would miss the part where both moved at once.
     */
    public function lineTotalVariance(): float
    {
        $recorded = $this->recorded_quantity * (float) $this->recorded_unit_cost;
        $verified = $this->verified_quantity * (float) $this->verified_unit_cost;

        return round($verified - $recorded, 2);
    }

    public function changesAnything(): bool
    {
        return $this->quantityVariance() !== 0 || abs($this->unitCostVariance()) >= 0.01;
    }
}
