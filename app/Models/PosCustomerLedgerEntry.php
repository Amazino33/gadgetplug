<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only, same discipline as AccountabilityLedgerEntry, FinancialLedgerEntry
 * and StockAccountabilityEntry: a mistake is corrected by posting an opposing
 * row, never by editing or deleting one.
 *
 * These rows say a named customer owes money. A record that can be quietly
 * rewritten afterwards is worth nothing to the person it names — and worth less
 * than nothing to the staff member who has to stand behind it.
 *
 * Outstanding is always SUM(amount) over these rows. There is no balance column
 * anywhere, so a balance can never drift from the history that produced it.
 */
class PosCustomerLedgerEntry extends Model
{
    protected $guarded = [];

    // No update path exists, so an updated_at column would only ever hold a
    // duplicate of created_at. Same reasoning as AccountabilityLedgerEntry.
    public const UPDATED_AT = null;

    protected $casts = [
        'amount'      => 'decimal:2',
        'occurred_at' => 'date',
        'created_at'  => 'datetime',
    ];

    /** Adds to what is owed. */
    public const DIRECTION_CHARGE = 'charge';

    /** Money actually received from the customer. */
    public const DIRECTION_PAYMENT = 'payment';

    /** Owner's decision to stop pursuing the balance; the loss is real. */
    public const DIRECTION_WRITEOFF = 'writeoff';

    public const DIRECTIONS = [
        self::DIRECTION_CHARGE,
        self::DIRECTION_PAYMENT,
        self::DIRECTION_WRITEOFF,
    ];

    /** The two that reduce a balance — payments and write-offs behave alike here. */
    public const REDUCING_DIRECTIONS = [
        self::DIRECTION_PAYMENT,
        self::DIRECTION_WRITEOFF,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->direction, self::DIRECTIONS, true)) {
                throw new LogicException('PosCustomerLedgerEntry direction must be one of: '.implode(', ', self::DIRECTIONS).'.');
            }

            // The sign convention is what makes outstanding a plain SUM, so it
            // is enforced here rather than trusted to every caller.
            if ($entry->direction === self::DIRECTION_CHARGE && (float) $entry->amount < 0) {
                throw new LogicException('A charge must increase what is owed, so its amount cannot be negative.');
            }

            if ($entry->direction !== self::DIRECTION_CHARGE && (float) $entry->amount > 0) {
                throw new LogicException('Payments and write-offs reduce what is owed, so their amount cannot be positive.');
            }
        });

        static::updating(function () {
            throw new LogicException('PosCustomerLedgerEntry rows are append-only and can never be updated. Post an opposing entry instead.');
        });

        static::deleting(function () {
            throw new LogicException('PosCustomerLedgerEntry rows are append-only and can never be deleted. Post an opposing entry instead.');
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCharge(): bool
    {
        return $this->direction === self::DIRECTION_CHARGE;
    }

    public function isPayment(): bool
    {
        return $this->direction === self::DIRECTION_PAYMENT;
    }

    public function isWriteoff(): bool
    {
        return $this->direction === self::DIRECTION_WRITEOFF;
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeCharges(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_CHARGE);
    }

    public function scopeReducing(Builder $query): Builder
    {
        return $query->whereIn('direction', self::REDUCING_DIRECTIONS);
    }
}
