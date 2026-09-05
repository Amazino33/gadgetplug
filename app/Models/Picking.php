<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One trip: what a picker took, from which branch, on which day.
 */
class Picking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'taken_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Generated after insert so the number follows the row, exactly as
        // Procurement does it.
        static::created(function (self $picking) {
            $picking->updateQuietly([
                'reference' => 'GP-PICK-'.str_pad((string) $picking->id, 5, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(Picker::class);
    }

    /** Whoever let the goods go. */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PickingItem::class);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForStore(Builder $query, ?int $storeId): Builder
    {
        return $query->when($storeId, fn (Builder $q) => $q->where('store_id', $storeId));
    }
}
