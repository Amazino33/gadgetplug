<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A word staff searched with before picking a product. Counts once it has
 * been used SearchShortcuts::MIN_HITS times. See App\Services\Products\SearchShortcuts.
 */
class ProductSearchShortcut extends Model
{
    protected $guarded = [];

    protected $casts = [
        'hits'         => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
