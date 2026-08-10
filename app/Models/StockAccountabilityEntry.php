<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Append-only, same discipline as FinancialLedgerEntry: a mistake is corrected
// by a reversing entry, never by editing or deleting the original. That matters
// more here than almost anywhere else in the app — these rows say a named member
// of staff is answerable for missing money, and a record that can be quietly
// rewritten afterwards is worth nothing to the person it accuses.
class StockAccountabilityEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_variance' => 'integer',
        'unit_cost'         => 'decimal:2',
        'amount'            => 'decimal:2',
        'occurred_at'       => 'date',
    ];

    public const DISPOSITIONS = ['written_off', 'recoverable', 'recorded', 'reversal'];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->disposition, self::DISPOSITIONS, true)) {
                throw new LogicException('StockAccountabilityEntry disposition must be one of: '.implode(', ', self::DISPOSITIONS).'.');
            }

            if ((float) $entry->amount < 0) {
                throw new LogicException('StockAccountabilityEntry amount must be non-negative — quantity_variance carries the sign.');
            }
        });

        static::updating(function () {
            throw new LogicException('StockAccountabilityEntry rows are append-only and can never be updated. Write a reversing entry instead.');
        });

        static::deleting(function () {
            throw new LogicException('StockAccountabilityEntry rows are append-only and can never be deleted. Write a reversing entry instead.');
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function auditSession(): BelongsTo
    {
        return $this->belongsTo(AuditSession::class);
    }

    public function storekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function isShortage(): bool
    {
        return $this->quantity_variance < 0;
    }
}
