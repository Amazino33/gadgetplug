<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One counting exercise at one branch ("November Full Count"), carried out as
 * a set of tasks over several days while the branch keeps selling.
 *
 * A branch has at most one open (draft or active) stock take at a time:
 * open_key holds the branch id until it closes, under a unique index.
 */
class StockTake extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'status'                     => self::STATUS_DRAFT,
        'variance_threshold_percent' => 10,
        'variance_threshold_value'   => 5000,
    ];

    protected $casts = [
        'start_date'                 => 'date',
        'end_date'                   => 'date',
        'closed_at'                  => 'datetime',
        'carried_product_ids'        => 'array',
        'variance_threshold_percent' => 'float',
        'variance_threshold_value'   => 'float',
    ];

    /** Set up; nobody has been given anything to count yet. */
    public const STATUS_DRAFT = 'draft';

    /** At least one task assigned. */
    public const STATUS_ACTIVE = 'active';

    /** Finished. Anything still uncounted stays uncounted, or is carried over. */
    public const STATUS_CLOSED = 'closed';

    protected static function booted(): void
    {
        static::creating(function (self $stockTake) {
            $stockTake->reference ??= self::nextReference((int) $stockTake->vendor_id);
        });

        static::saving(function (self $stockTake) {
            $stockTake->open_key = $stockTake->status === self::STATUS_CLOSED
                ? null
                : (string) $stockTake->store_id;
        });
    }

    /** ST-0001, ST-0002 … per vendor. The unique index settles a race. */
    public static function nextReference(int $vendorId): string
    {
        $next = static::query()->where('vendor_id', $vendorId)->count() + 1;

        do {
            $reference = 'ST-' . str_pad((string) $next++, 4, '0', STR_PAD_LEFT);
        } while (static::query()->where('vendor_id', $vendorId)->where('reference', $reference)->exists());

        return $reference;
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(StockTakeTask::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(StockTakeResult::class);
    }

    /** Barcodes found during this stock take's tasks that the system does not know. */
    public function unknownItems(): HasManyThrough
    {
        return $this->hasManyThrough(StockTakeUnknownItem::class, StockTakeTask::class);
    }

    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_CLOSED);
    }
}
