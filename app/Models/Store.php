<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

// One physical location belonging to a vendor. The vendor remains the tenant
// and the permissions team; a store never becomes either. Access to a store is
// a narrowing of vendor membership, held in store_user.
//
// Scoping follows the convention products already set: a plain vendor_id
// filter, applied deliberately at every call site. No global scope — the
// codebase has none, and adding one here would make this the only model in the
// app whose queries silently mean something different from what they say.
class Store extends Model
{
    use HasSlug;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    // Per-vendor uniqueness via extraScope, exactly as Product does it: two
    // vendors may both call a store "Main Store" without either one's slug
    // being pushed to "main-store-2" by the other's existence.
    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(80)
            ->doNotGenerateSlugsOnUpdate()
            ->extraScope(fn ($builder) => $builder->where('vendor_id', $this->vendor_id));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')
            ->withTimestamps();
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStoreStock::class);
    }

    public function orderItemAllocations(): HasMany
    {
        return $this->hasMany(OrderItemStoreAllocation::class);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
