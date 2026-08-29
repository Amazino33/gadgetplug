<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// One receipt of stock into one branch, and what those units cost.
//
// Written and drawn down only through App\Services\Inventory\StockCostLayers,
// which is what keeps the layers in step with product_store_stocks. Nothing
// else should create or mutate these rows.
class StockCostLayer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_cost'          => 'decimal:2',
        'quantity_received'  => 'integer',
        'quantity_remaining' => 'integer',
        'received_at'        => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Units of this layer already sold or otherwise moved out. */
    public function quantityConsumed(): int
    {
        return $this->quantity_received - $this->quantity_remaining;
    }

    public function isExhausted(): bool
    {
        return $this->quantity_remaining <= 0;
    }

    /** What the units still held in this layer are worth. Null cost is worth nothing it can prove. */
    public function remainingValue(): float
    {
        return $this->quantity_remaining * (float) ($this->unit_cost ?? 0);
    }
}
