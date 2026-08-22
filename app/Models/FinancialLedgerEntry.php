<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

// Append-only ledger, same discipline as WalletTransaction. Rows are never
// corrected in place — a mistake gets a reversing entry (equal amount,
// opposite direction), not an edit or a delete. Unlike WalletTransaction this
// also blocks delete(), not just update() — deleting a posted movement would
// silently understate history in a way an update-only guard doesn't catch.
class FinancialLedgerEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'      => 'decimal:2',
        'occurred_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->direction, ['in', 'out'], true)) {
                throw new LogicException('FinancialLedgerEntry direction must be "in" or "out".');
            }

            if ((float) $entry->amount < 0) {
                throw new LogicException('FinancialLedgerEntry amount must be non-negative — direction carries the sign.');
            }
        });

        static::updating(function () {
            throw new LogicException('FinancialLedgerEntry rows are append-only and can never be updated. Write a reversing entry instead.');
        });

        static::deleting(function () {
            throw new LogicException('FinancialLedgerEntry rows are append-only and can never be deleted. Write a reversing entry instead.');
        });
    }

    /**
     * The entry is account-scoped by design, but every account belongs to a
     * vendor — so this exposes the owning vendor for anything that scopes by
     * it, the activity log included. Without it a posted ledger entry logged
     * with a null vendor_id and never appeared in that vendor's feed.
     */
    public function getVendorIdAttribute(): ?int
    {
        return $this->account?->vendor_id;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
