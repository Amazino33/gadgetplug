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
        'cash_shortage',
        'cash_overage',
    ];

    public const RECOVERY_TYPES = [
        'recovery_cash',
        'recovery_salary',
        'recovery_manual',
    ];

    /**
     * End-of-day drawer and terminal differences, posted from a cash-up session.
     *
     * Two types rather than one signed 'cash_variance' so the sign invariant below
     * survives intact: a shortage increases what the cashier owes, an overage
     * reduces it, and outstanding stays a plain SUM with no CASE anywhere. None of
     * the stock-shaped columns (shortage_qty, the cost snapshots) apply to these
     * rows; they carry source_type/source_id pointing at the session instead.
     */
    public const CASH_VARIANCE_TYPES = [
        'cash_shortage',
        'cash_overage',
    ];

    /**
     * Types that add to what a person owes, and therefore may not be negative.
     *
     * Everything not listed here reduces the balance and may not be positive.
     * Kept as a list rather than a comparison against 'charge' so a new increasing
     * type cannot be added without deciding which side of this rule it falls on.
     */
    public const INCREASING_TYPES = [
        'charge',
        'cash_shortage',
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
            if (in_array($entry->entry_type, self::INCREASING_TYPES, true) && (float) $entry->amount < 0) {
                throw new LogicException('A '.$entry->entry_type.' must increase what is owed, so its amount cannot be negative.');
            }

            if (! in_array($entry->entry_type, self::INCREASING_TYPES, true) && (float) $entry->amount > 0) {
                throw new LogicException('Recoveries, write-off conversions and overages reduce what is owed, so their amount cannot be positive.');
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

    /**
     * The branch the loss happened at.
     *
     * Nullable: every row written before cash-up existed has none, and a stock
     * charge raised against the whole business legitimately has none either.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Whoever is being charged.
     *
     * Named storekeeper_id because stock shortages came first, but it means "the
     * member of staff this row accuses" — for a cash variance that is the cashier.
     * Left as it is rather than renamed: the column is referenced across the
     * accountability build, and a rename buys nothing the docblock cannot.
     */
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

    /** A drawer or terminal difference from an end-of-day cash-up. */
    public function isCashVariance(): bool
    {
        return in_array($this->entry_type, self::CASH_VARIANCE_TYPES, true);
    }

    /**
     * Whether this row discloses product cost.
     *
     * Cash-variance rows leave every cost snapshot null, so they carry nothing the
     * view_cost_price permission needs to protect — which lets a cash-up review
     * screen show them to a manager who may not see stock costs.
     */
    public function disclosesCost(): bool
    {
        foreach (self::COST_SENSITIVE_FIELDS as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return false;
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

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /** Rows produced by one source document — a cash-up session, say. */
    public function scopeForSource(Builder $query, string $sourceType, int $sourceId): Builder
    {
        return $query->where('source_type', $sourceType)->where('source_id', $sourceId);
    }

    public function scopeCashVariances(Builder $query): Builder
    {
        return $query->whereIn('entry_type', self::CASH_VARIANCE_TYPES);
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
