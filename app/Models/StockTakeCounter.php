<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who counts stock but has no use for the site (see
 * StockTakeCounters). A user underneath, with a name and a code.
 */
class StockTakeCounter extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'is_active'         => 'boolean',
        'locked_until'      => 'datetime',
        'last_signed_in_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
