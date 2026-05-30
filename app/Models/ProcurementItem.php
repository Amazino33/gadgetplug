<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
