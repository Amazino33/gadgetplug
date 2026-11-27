<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place in one branch where goods sit: Aisle 1, Fridge, Store Room.
 *
 * Stock takes are planned and counted by aisle. A product can be in several
 * (the map is aisle_products); an aisle belongs to exactly one branch.
 */
class Aisle extends Model
{
    protected $guarded = [];

    /** The database defaults, so a model just created knows them too. */
    protected $attributes = [
        'is_active'  => true,
        'sort_order' => 0,
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_quick'   => 'boolean',
        'sort_order' => 'integer',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** The aisle map: products recorded as sitting here. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'aisle_products')
            ->using(AisleProduct::class)
            ->withPivot(['id', 'vendor_id', 'source', 'mapped_by', 'mapped_at'])
            ->withTimestamps();
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AisleProduct::class);
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(StockTakeTask::class, 'stock_take_task_aisles')
            ->withPivot('lock_key')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Real aisles: not the private areas a Quick count makes for itself. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('aisles.is_quick', false);
    }

    /** The order a person walks the shop in. */
    public function scopeOrdered(Builder $query): Builder
    {
        // Qualified: through a task's aisles the pivot has an id too.
        return $query->orderBy('aisles.sort_order')->orderBy('aisles.name')->orderBy('aisles.id');
    }

    /** "A01 · Drinks" when there is a code, otherwise the name. */
    public function label(): string
    {
        return $this->code ? "{$this->code} · {$this->name}" : $this->name;
    }
}
