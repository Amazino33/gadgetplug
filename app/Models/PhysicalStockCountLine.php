<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One product's count: what was found, against what the system said at that moment.
 *
 * The system figure is stored rather than looked up later on purpose. Stock
 * keeps moving; a variance recomputed next week would quietly heal itself and
 * the evidence of a gap would be gone.
 */
class PhysicalStockCountLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'counted_quantity' => 'integer',
        'system_quantity'  => 'integer',
        'unit_cost'        => 'decimal:2',
        'unit_price'       => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A counted line is never edited. Record a fresh count instead.');
        });
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(PhysicalStockCount::class, 'physical_stock_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Positive means missing, negative means more was found than expected. */
    public function variance(): int
    {
        return $this->system_quantity - $this->counted_quantity;
    }

    /** What the gap cost the business to buy. */
    public function varianceValue(): float
    {
        return round($this->variance() * (float) ($this->unit_cost ?? 0), 2);
    }

    /**
     * What the gap would have sold for.
     *
     * The figure that speaks to the cash side: if these units left as sales
     * nobody rang up, this is what the till should have taken and did not.
     */
    public function varianceAtSellingPrice(): float
    {
        return round($this->variance() * (float) ($this->unit_price ?? 0), 2);
    }
}
