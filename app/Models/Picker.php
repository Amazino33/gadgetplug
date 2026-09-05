<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trader who takes goods to sell in their own shop and pays as they sell.
 *
 * Separate from PosCustomer on purpose — see the table migration. The staff
 * know these people by name and by which shop in the plaza is theirs, which is
 * why both are on the record rather than an account number nobody uses.
 */
class Picker extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function pickings(): HasMany
    {
        return $this->hasMany(Picking::class);
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
