<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of a stock take given to one person: count these aisles, recount
 * these products, or map these aisles.
 *
 * A count task holds its aisles while it is open (assigned or in progress):
 * each holding row carries lock_key "{stock_take_id}:{aisle_id}" under a
 * unique index, so the same aisle cannot be in two open count tasks of one
 * stock take even if two managers assign it at the same moment. The key is
 * cleared as soon as the task stops being open.
 */
class StockTakeTask extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'type'   => self::TYPE_COUNT,
        'status' => self::STATUS_ASSIGNED,
    ];

    protected $casts = [
        'due_date'     => 'date',
        'is_spot_check' => 'boolean',
        'started_at'   => 'datetime',
        'submitted_at' => 'datetime',
        'device_seen_at' => 'datetime',
        'device_pending' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public const TYPE_COUNT   = 'count';
    public const TYPE_RECOUNT = 'recount';
    public const TYPE_MAPPING = 'mapping';

    /** Given to someone; nothing entered yet. */
    public const STATUS_ASSIGNED = 'assigned';

    /** The first entry has been saved. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** Handed in. The counter can no longer change it; results are built. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Every result decided, none sent to recount. */
    public const STATUS_APPROVED = 'approved';

    /** Every result decided, some sent to recount. */
    public const STATUS_PARTIALLY_APPROVED = 'partially_approved';

    public const STATUS_CANCELLED = 'cancelled';

    /** Still with the person it was given to. */
    public const OPEN_STATUSES = [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS];

    protected static function booted(): void
    {
        static::saved(function (self $task) {
            if ($task->wasChanged('status') && ! $task->isOpen()) {
                $task->releaseAisles();
            }
        });
    }

    public static function lockKey(int $stockTakeId, int $aisleId): string
    {
        return "{$stockTakeId}:{$aisleId}";
    }

    /**
     * Put aisles on this task. A count task holds them while open; a
     * QueryException (unique lock_key) means another open count task of the
     * same stock take already has one of them.
     *
     * @param  array<int, int>  $aisleIds
     */
    public function attachAisles(array $aisleIds): void
    {
        $holds = $this->type === self::TYPE_COUNT && $this->stock_take_id && $this->isOpen();

        $this->aisles()->attach(collect($aisleIds)->mapWithKeys(fn ($aisleId) => [
            (int) $aisleId => [
                'lock_key' => $holds ? self::lockKey((int) $this->stock_take_id, (int) $aisleId) : null,
            ],
        ])->all());
    }

    /** Let go of the aisles, so another task of the stock take may take them. */
    public function releaseAisles(): void
    {
        StockTakeTaskAisle::query()
            ->where('stock_take_task_id', $this->id)
            ->whereNotNull('lock_key')
            ->update(['lock_key' => null]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isRecount(): bool
    {
        return $this->type === self::TYPE_RECOUNT;
    }

    private ?bool $quick = null;

    /**
     * A Quick count: no aisles set up first. Its own private area stands for
     * "no aisle", and the counter may say which real aisle of the branch they
     * are in as they go — counting and mapping in one walk.
     */
    public function isQuick(): bool
    {
        return $this->quick ??= ! $this->isRecount()
            && $this->type === self::TYPE_COUNT
            && $this->aisles()->where('aisles.is_quick', true)->exists();
    }

    /** Who holds this aisle on another open count task of the same stock take, if anyone. */
    public function aisleHeldByOther(int $aisleId): ?string
    {
        return \Illuminate\Support\Facades\DB::table('stock_take_task_aisles')
            ->join('stock_take_tasks', 'stock_take_tasks.id', '=', 'stock_take_task_aisles.stock_take_task_id')
            ->join('users', 'users.id', '=', 'stock_take_tasks.assigned_to')
            ->where('stock_take_tasks.stock_take_id', $this->stock_take_id)
            ->where('stock_take_tasks.id', '!=', $this->id)
            ->where('stock_take_task_aisles.aisle_id', $aisleId)
            ->whereNotNull('stock_take_task_aisles.lock_key')
            ->value('users.name');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
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

    /** The task a recount re-checks. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function recounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aisles(): BelongsToMany
    {
        return $this->belongsToMany(Aisle::class, 'stock_take_task_aisles')
            ->using(StockTakeTaskAisle::class)
            ->withPivot('lock_key')
            ->withTimestamps();
    }

    /** What a recount covers. Empty for count and mapping tasks. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'stock_take_task_products')
            ->withTimestamps();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StockTakeEntry::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(StockTakeResult::class);
    }

    public function unknownItems(): HasMany
    {
        return $this->hasMany(StockTakeUnknownItem::class);
    }

    /** The audit lines its approved results wrote. */
    public function auditLines(): HasMany
    {
        return $this->hasMany(AuditSession::class, 'stock_take_task_id');
    }
}
