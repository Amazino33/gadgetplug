<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One serial or IMEI number, received on a purchase line. One row per unit. */
class ProcurementItemSerial extends Model
{
    protected $fillable = ['procurement_item_id', 'product_id', 'vendor_id', 'serial'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProcurementItem::class, 'procurement_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
