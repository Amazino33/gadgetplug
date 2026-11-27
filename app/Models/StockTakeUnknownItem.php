<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on the shelf whose barcode the system does not know, recorded
 * while mapping or counting so a manager can create or link the product.
 */
class StockTakeUnknownItem extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $casts = [
        'qty'         => AsQuantity::class,
        'resolved_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';

    /** Created as a product, or linked to an existing one. */
    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(StockTakeTask::class, 'stock_take_task_id');
    }

    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'resolved_product_id');
    }
}
