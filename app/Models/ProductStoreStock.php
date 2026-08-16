<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// How much of one product sits in one store. Nothing reads this yet — the
// mutators and every reader still work off products.stock_quantity /
// products.reserved_stock, and will until a later phase moves them across.
//
// Non-standard table name because this is a stock record with its own columns,
// not a pivot: product_store would read as a bare many-to-many.
class ProductStoreStock extends Model
{
    protected $table = 'product_store_stock';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
