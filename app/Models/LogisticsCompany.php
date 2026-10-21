<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery firm. vendor_id null = a PLATFORM logistics company — the outside
 * firms GadgetPlug sends online orders through, managed in the admin panel.
 * A vendor's own companies (vendor_id set) predate that and are never offered
 * for online orders.
 */
class LogisticsCompany extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active'          => 'boolean',
        'coverage_lga_ids'   => 'array',
        'coverage_state_ids' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deliveryPersons(): HasMany
    {
        return $this->hasMany(DeliveryPerson::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('vendor_id');
    }

    public function isPlatform(): bool
    {
        return $this->vendor_id === null;
    }

    /** Covers this LGA directly, or its whole state. */
    public function covers(?Lga $lga): bool
    {
        if (! $lga) {
            return false;
        }

        return in_array($lga->id, array_map('intval', $this->coverage_lga_ids ?? []), true)
            || in_array($lga->state_id, array_map('intval', $this->coverage_state_ids ?? []), true);
    }

    /** Where the dispatch message goes: the WhatsApp number, else the phone. */
    public function dispatchNumber(): ?string
    {
        return filled($this->whatsapp) ? $this->whatsapp : ($this->phone ?: null);
    }
}
