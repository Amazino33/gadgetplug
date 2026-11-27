<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** One product recorded as sitting in one aisle. */
class AisleProduct extends Pivot
{
    protected $table = 'aisle_products';

    public $incrementing = true;

    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'source' => self::SOURCE_MAPPING,
    ];

    protected $casts = [
        'mapped_at' => 'datetime',
    ];

    /** Someone walked the aisle and scanned it there. */
    public const SOURCE_MAPPING = 'mapping';

    /** A counter found it there, and the count was approved. */
    public const SOURCE_COUNT = 'count';

    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }
}
