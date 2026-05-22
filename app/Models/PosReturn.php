<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosReturn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'return_items'  => 'array',
        'refund_amount' => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'original_sale_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}
