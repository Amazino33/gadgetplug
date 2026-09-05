<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product line on a trip.
 *
 * quantity is what left the shelf and never changes. Everything else — units
 * still held, units paid for, money sitting part-paid against the next unit —
 * is derived from the ledger by PickingLedger, so nothing here can drift.
 */
class PickingItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity'  => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function picking(): BelongsTo
    {
        return $this->belongsTo(Picking::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PickingLedgerEntry::class);
    }
}
