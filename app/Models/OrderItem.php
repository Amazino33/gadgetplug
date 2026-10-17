<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class OrderItem extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        // Snapshot both names the moment a line is created — on every path
        // that creates one (checkout, reseller wallet purchase, anything later)
        // — so renaming a product never rewrites what an existing order says.
        static::creating(function (self $item) {
            if ($item->product_name !== null && $item->product_display_name !== null) {
                return;
            }

            $product = Product::find($item->product_id);

            if (! $product) {
                return;
            }

            $item->product_name ??= $product->name;
            $item->product_display_name ??= $product->displayName();
        });
    }

    /** What the customer was shown for this line — online name, frozen at order time. */
    public function customerName(): string
    {
        return $this->product_display_name
            ?? $this->product?->displayName()
            ?? 'Item';
    }

    /** The store's own shelf name for this line, frozen at order time. */
    public function internalName(): string
    {
        return $this->product_name
            ?? $this->product?->name
            ?? 'Item';
    }

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
