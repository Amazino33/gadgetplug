<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Which store(s) supplied this line. Written at reservation, read by
    // dispatch, release and per-store sales.
    public function storeAllocations()
    {
        return $this->hasMany(OrderItemStoreAllocation::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
