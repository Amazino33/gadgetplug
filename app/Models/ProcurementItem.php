<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementItem extends Model
{
    protected $fillable = [
        'procurement_id', 'product_id', 'barcode',
        'quantity', 'unit_cost', 'selling_price',
    ];

    protected $casts = [
        'unit_cost'     => 'decimal:2',
        'selling_price' => 'decimal:2',
    ];

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Every account anyone has given of this line, oldest first.
     *
     * quantity and unit_cost on this row are never touched by any of them —
     * what the storekeeper wrote down stays written down, and the corrections
     * sit beside it.
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(ProcurementItemCorrection::class)->orderBy('id');
    }

    /** The last word on this line, or null while nobody has disputed it. */
    public function latestCorrection(): ?ProcurementItemCorrection
    {
        // reorder(), not latest(): the relation already sorts ascending, and
        // a second orderBy would simply queue up behind the first and hand
        // back the oldest correction — the storekeeper's original dispute
        // instead of the last word on the line.
        return $this->relationLoaded('corrections')
            ? $this->corrections->sortBy('id')->last()
            : $this->corrections()->reorder('id', 'desc')->first();
    }

    public function isCorrected(): bool
    {
        return $this->latestCorrection() !== null;
    }

    /**
     * What this line is currently understood to be.
     *
     * Falls back to the recorded figure, so every caller can read the verified
     * value unconditionally instead of branching on whether a correction
     * happened. Only quantity and unit cost are ever correctable; product and
     * supplier are not in dispute, they are facts of the delivery.
     */
    public function verifiedQuantity(): int
    {
        return $this->latestCorrection()?->verified_quantity ?? (int) $this->quantity;
    }

    public function verifiedUnitCost(): float
    {
        $correction = $this->latestCorrection();

        return (float) ($correction?->verified_unit_cost ?? $this->unit_cost);
    }

    public function verifiedLineTotal(): float
    {
        return round($this->verifiedQuantity() * $this->verifiedUnitCost(), 2);
    }

    /** Units the approver says never arrived. Negative means short. */
    public function quantityVariance(): int
    {
        return $this->verifiedQuantity() - (int) $this->quantity;
    }

    public function unitCostVariance(): float
    {
        return round($this->verifiedUnitCost() - (float) $this->unit_cost, 2);
    }

    public function lineTotalVariance(): float
    {
        return round($this->verifiedLineTotal() - $this->lineTotal(), 2);
    }

    public function lineTotal(): float
    {
        return (float) $this->unit_cost * $this->quantity;
    }

    // Returns the cost variance % relative to the product's current cost_price.
    // Positive = more expensive, negative = cheaper.
    public function costVariancePct(): ?float
    {
        $historical = (float) ($this->product?->cost_price ?? 0);
        if ($historical <= 0) return null;
        return (($this->unit_cost - $historical) / $historical) * 100;
    }

    public function hasCostVariance(): bool
    {
        $pct = $this->costVariancePct();
        return $pct !== null && abs($pct) > 10;
    }
}
