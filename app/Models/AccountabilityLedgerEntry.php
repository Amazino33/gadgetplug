<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Append-only, same discipline as FinancialLedgerEntry and
// StockAccountabilityEntry: a mistake is corrected by posting an opposing row,
// never by editing or deleting. These rows say a named person owes money, so a
// record that can be quietly rewritten afterwards is worth nothing to the
// person it accuses.
class AccountabilityLedgerEntry extends Model
{
    protected $guarded = [];

    // No update path exists, so an updated_at column would only ever hold a
    // duplicate of created_at.
    public const UPDATED_AT = null;

    protected $casts = [
        'shortage_qty'        => 'integer',
        'unit_cost_snapshot'  => 'decimal:2',
        'unit_price_snapshot' => 'decimal:2',
        'charge_amount'       => 'decimal:2',
        'cost_component'      => 'decimal:2',
        'margin_component'    => 'decimal:2',
        'amount'              => 'decimal:2',
        'price_fallback'      => 'boolean',
        'created_at'          => 'datetime',
    ];

    public const ENTRY_TYPES = [
        'charge',
        'recovery_cash',
        'recovery_salary',
        'recovery_manual',
        'writeoff_conversion',
    ];

    public const RECOVERY_TYPES = [
        'recovery_cash',
        'recovery_salary',
        'recovery_manual',
    ];

    /**
     * Fields that disclose product cost — directly, or by subtracting one from
     * another. Phase 3/4 UI must gate every one of these behind the existing
     * view_cost_price permission (see ProductForm::canSeeCostPrice()); charge_amount
     * is safe to show on its own, but not alongside these.
     */
    public const COST_SENSITIVE_FIELDS = [
        'unit_cost_snapshot',
        'cost_component',
        'margin_component',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->entry_type, self::ENTRY_TYPES, true)) {
                throw new LogicException('AccountabilityLedgerEntry entry_type must be one of: '.implode(', ', self::ENTRY_TYPES).'.');
            }

            // The sign convention is what makes outstanding a plain SUM, so it is
            // enforced here rather than trusted to every caller.
            if ($entry->entry_type === 'charge' && (float) $entry->amount < 0) {
                throw new LogicException('A charge must increase what is owed, so its amount cannot be negative.');
            }

            if ($entry->entry_type !== 'charge' && (float) $entry->amount > 0) {
                throw new LogicException('Recoveries and write-off conversions reduce what is owed, so their amount cannot be positive.');
            }
        });

        static::updating(function () {
            throw new LogicException('AccountabilityLedgerEntry rows are append-only and can never be updated. Post an opposing entry instead.');
        });

        static::deleting(function () {
            throw new LogicException('AccountabilityLedgerEntry rows are append-only and can never be deleted. Post an opposing entry instead.');
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function storekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'storekeeper_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCharge(): bool
    {
        return $this->entry_type === 'charge';
    }

    public function isRecovery(): bool
    {
        return in_array($this->entry_type, self::RECOVERY_TYPES, true);
    }

    // ── Derived balances ─────────────────────────────────────────────────────
    // Never stored. Outstanding is the sum of the signed amounts, so a
    // writeoff_conversion naturally stops a converted case showing as owed by
    // the person — it posts the negative remainder like any other reduction.

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForStorekeeper(Builder $query, int $storekeeperId): Builder
    {
        return $query->where('storekeeper_id', $storekeeperId);
    }

    public function scopeForCase(Builder $query, int $caseId): Builder
    {
        return $query->where('case_id', $caseId);
    }

    public static function outstandingForStorekeeper(int $storekeeperId, int $vendorId): float
    {
        return round((float) static::query()
            ->forVendor($vendorId)
            ->forStorekeeper($storekeeperId)
            ->sum('amount'), 2);
    }

    public static function outstandingForCase(int $caseId, int $vendorId): float
    {
        return round((float) static::query()
            ->forVendor($vendorId)
            ->forCase($caseId)
            ->sum('amount'), 2);
    }
}
