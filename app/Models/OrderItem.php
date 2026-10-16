<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrderItem extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function order() {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Whether this line's stock is ours to reserve, dispatch or release.
    // False for a resold (VendorLink) listing. See Product::tracksStock().
    public function tracksStock(): bool
    {
        return $this->product?->tracksStock() ?? true;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['quantity', 'unit_price', 'product_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Order item ' . $event);
    }
}
