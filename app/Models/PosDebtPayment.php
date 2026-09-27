<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What a debt repayment actually was, collected at the till.
 *
 * Append-only, same discipline as PosCustomerLedgerEntry and Expense: a
 * mistake here is corrected by writing an opposing entry (a write-off, or a
 * fresh charge), never by rewriting what was collected.
 */
class PosDebtPayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'       => 'decimal:2',
        'collected_at' => 'date',
        'created_at'   => 'datetime',
    ];

    public const METHODS = ['cash', 'card', 'bank_transfer'];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->method, self::METHODS, true)) {
                throw new LogicException('PosDebtPayment method must be one of: '.implode(', ', self::METHODS).'.');
            }

            if ((float) $entry->amount <= 0) {
                throw new LogicException('A debt repayment amount must be positive.');
            }
        });

        static::updating(function () {
            throw new LogicException('PosDebtPayment rows are append-only and can never be updated.');
        });

        static::deleting(function () {
            throw new LogicException('PosDebtPayment rows are append-only and can never be deleted.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'pos_customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(PosCustomerLedgerEntry::class, 'pos_customer_ledger_entry_id');
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeCash(Builder $query): Builder
    {
        return $query->where('method', 'cash');
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('method', ['card', 'bank_transfer']);
    }
}
