<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Units of one order line supplied by one store. The allocation is written
// once, at reservation, and read back by dispatch, release and per-store
// sales — so no later step has to guess where the goods came from.
class OrderItemStoreAllocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
