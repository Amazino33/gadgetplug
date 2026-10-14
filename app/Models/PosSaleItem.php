<?php

namespace App\Models;

use App\Support\Quantity;
use App\Casts\AsQuantity;
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
        'half_unit_price' => 'decimal:2',
        'cost_estimated'  => 'boolean',
        'quantity' => AsQuantity::class,
        'unit_qty' => AsQuantity::class,
    ];

    /**
     * How this line reads on a printed receipt: the quantity label, the price
     * per unit sold, and — when the half was priced on its own — that half.
     *
     * "1 Crate × ₦9,700", not "24 × ₦404.17". A line with a half in it (a shop
     * that sells halves) reads "1½ Crate × ₦10,100 (½ at ₦5,200)", so the
     * figures on the paper add up to the line total beside them. Every other
     * line is exactly what the receipt has always printed.
     *
     * @return array{quantity: string|int|float, unit_price: mixed, half_price: ?float}
     */
    public function receiptLine(): array
    {
        $units = $this->unit_qty ?? $this->quantity;

        if (Quantity::hasHalf($units)) {
            $price = (float) ($this->sold_unit_price ?? $this->unit_price);
            $half  = $this->half_unit_price !== null ? (float) $this->half_unit_price : null;

            return [
                'quantity'   => trim(Quantity::format($units, true).' '.($this->unit_name ?? '')),
                'unit_price' => $price,
                'half_price' => $half !== null && abs($half - round($price / 2, 2)) > 0.004 ? $half : null,
            ];
        }

        return [
            'quantity'   => $this->soldInLargerUnit() ? "{$this->unit_qty} {$this->unit_name}" : $this->quantity,
            'unit_price' => $this->soldInLargerUnit() ? $this->sold_unit_price : $this->unit_price,
            'half_price' => null,
        ];
    }

    /** True when this line was sold in a unit bigger than the base unit (a crate, not a bottle). */
    public function soldInLargerUnit(): bool
    {
        return $this->unit_qty !== null && Quantity::of($this->unit_qty) !== Quantity::of($this->quantity);
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
