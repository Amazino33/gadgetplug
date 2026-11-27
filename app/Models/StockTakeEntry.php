<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product counted in one aisle within a task, as the counter entered it.
 *
 * Holds no system quantity, by design: the counter's screens and responses
 * must never carry one, and the figure to compare with is worked out from the
 * ledger for counted_at when results are built (see the migration).
 */
class StockTakeEntry extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'is_unlisted'     => false,
        'is_not_on_shelf' => false,
    ];

    protected $casts = [
        'counted_qty_base' => AsQuantity::class,
        'damaged_qty_base' => AsQuantity::class,
        'expired_qty_base' => AsQuantity::class,
        'entry_breakdown'  => 'array',
        'counted_at'       => 'datetime',
        'is_unlisted'      => 'boolean',
        'is_not_on_shelf'  => 'boolean',
        'device_counted_at'  => 'datetime',
        'counted_at_flagged' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(StockTakeTask::class, 'stock_take_task_id');
    }

    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
