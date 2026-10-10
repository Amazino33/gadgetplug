<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSaleItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total'           => 'decimal:2',
        'cost_total'      => 'decimal:2',
        'sold_unit_price' => 'decimal:2',
        'cost_estimated'  => 'boolean',
    ];

    /** True when this line was sold in a unit bigger than the base unit (a crate, not a bottle). */
    public function soldInLargerUnit(): bool
    {
        return $this->unit_qty !== null && (int) $this->unit_qty !== (int) $this->quantity;
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
