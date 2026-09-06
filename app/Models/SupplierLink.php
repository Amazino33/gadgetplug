<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permission for one vendor to sell from another vendor's catalogue.
 *
 * This row is the gate. Every vendor's catalogue is otherwise sealed off from
 * every other's, and nothing in the system reads across that line without an
 * active link saying it may. Created by an admin only — a vendor granting
 * themselves access to somebody else's shop is the thing this prevents.
 */
class SupplierLink extends Model
{
    protected $guarded = [];

    // Mirrors the column defaults, so a freshly made link reads the same before
    // and after it is reloaded — otherwise is_active is null in memory and true
    // in the database, which is a trap for every caller.
    protected $attributes = [
        'markup_percent' => 0,
        'rounding_rule'  => 'ends_990',
        'is_active'      => true,
    ];

    protected $casts = [
        'markup_percent' => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    /** The vendor doing the selling — owns the listings and the payable. */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'reseller_vendor_id');
    }

    /** The vendor whose catalogue is being sold. Never written to. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_vendor_id');
    }

    /** Listings the reseller has published from this supplier. */
    public function listings(): HasMany
    {
        return $this->hasMany(Product::class, 'supplier_link_id');
    }

    public function payableEntries(): HasMany
    {
        return $this->hasMany(SupplierPayableEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForReseller(Builder $query, int $vendorId): Builder
    {
        return $query->where('reseller_vendor_id', $vendorId);
    }

    /**
     * Whether this link may be used to read the supplier's catalogue right now.
     *
     * Deactivating a link stops new listings being published and stops prices
     * resolving; it deliberately does not delete anything already published or
     * anything already owed.
     */
    public function isUsable(): bool
    {
        return $this->is_active;
    }
}
