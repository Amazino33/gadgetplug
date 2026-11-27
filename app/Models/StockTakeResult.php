<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsQuantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One product in one submitted task: what was counted across the task's
 * aisles, against what the shelf held at the moment of counting.
 *
 * Approval applies the variance, never the counted number: the shop has kept
 * selling since, so new stock = stock now + variance.
 */
class StockTakeResult extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'status'        => self::STATUS_PENDING,
        'needs_recount' => false,
    ];

    protected $casts = [
        'total_counted_base'     => AsQuantity::class,
        'damaged_qty_base'       => AsQuantity::class,
        'expired_qty_base'       => AsQuantity::class,
        'system_qty_at_count'    => AsQuantity::class,
        'variance_qty'           => AsQuantity::class,
        'applied_system_qty'     => AsQuantity::class,
        'applied_adjustment_qty' => AsQuantity::class,
        'unit_cost'              => 'float',
        'variance_value'         => 'float',
        'needs_recount'          => 'boolean',
        'counted_at'             => 'datetime',
        'approved_at'            => 'datetime',
        'recheck_needed_at'      => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';

    /** Not accepted; stock untouched. Also the count not chosen of a recount pair. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SENT_TO_RECOUNT = 'sent_to_recount';

    /**
     * What a decision may change. The counted figures, the moment and the
     * variance found are fixed once the result is built — approving decides
     * what to do about a gap; it never quietly changes what the gap was.
     */
    private const DECISION_COLUMNS = [
        'status', 'recount_task_id', 'variance_reason',
        'approved_by', 'approved_at', 'override_note',
        'applied_system_qty', 'applied_adjustment_qty',
        'inventory_ledger_id', 'audit_session_id',
        'recheck_needed_at',
        // The write-off of what was found damaged or expired, made on approval.
        'writeoff_ledger_id', 'writeoff_value',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $result) {
            $changing = array_values(array_diff(array_keys($result->getDirty()), ['updated_at']));

            if (array_diff($changing, self::DECISION_COLUMNS) !== []) {
                throw new LogicException('A stock take result records what was found. Recount rather than editing it.');
            }
        });
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(StockTakeTask::class, 'stock_take_task_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recountTask(): BelongsTo
    {
        return $this->belongsTo(StockTakeTask::class, 'recount_task_id');
    }

    /** On a recount's result: the original count it re-checks. */
    public function recountOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recount_of_id');
    }

    /** On an original: the recount's result, once submitted. */
    public function recountResult(): HasOne
    {
        return $this->hasOne(self::class, 'recount_of_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(InventoryLedger::class, 'inventory_ledger_id');
    }

    public function auditLine(): BelongsTo
    {
        return $this->belongsTo(AuditSession::class, 'audit_session_id');
    }

    /** Every audit line this result wrote: its approval, and re-checks after. */
    public function auditLines(): HasMany
    {
        return $this->hasMany(AuditSession::class, 'stock_take_result_id');
    }

    /** The counts behind this result: the product's entries in its task. */
    public function entries(): HasMany
    {
        return $this->hasMany(StockTakeEntry::class, 'stock_take_task_id', 'stock_take_task_id')
            ->where('stock_take_entries.product_id', $this->product_id);
    }
}
