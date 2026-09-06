<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A debt to the supplier, or a payment against it.
 *
 * Append-only. The balance is always the sum of these rows and is never stored,
 * so it cannot drift from the history that produced it — the same discipline
 * the financial, accountability and customer-debt ledgers follow.
 *
 * unit_cost is frozen when the units are delivered and never recomputed. The
 * supplier will change his price again; what he charged for these units on that
 * day is what is owed.
 */
class SupplierPayableEntry extends Model
{
    protected $guarded = [];

    // No update path exists, so an updated_at column would only ever hold a
    // duplicate of created_at.
    public const UPDATED_AT = null;

    protected $casts = [
        'quantity'   => 'integer',
        'unit_cost'  => 'decimal:2',
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** Units delivered to a customer — money now owed to the supplier. */
    public const TYPE_CHARGE = 'charge';

    /** Money handed to the supplier against what is owed. */
    public const TYPE_PAYMENT = 'payment';

    public const TYPES = [self::TYPE_CHARGE, self::TYPE_PAYMENT];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function supplierLink(): BelongsTo
    {
        return $this->belongsTo(SupplierLink::class);
    }

    /** The delivered line this charge came from. Null on payments. */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCharges(Builder $query): Builder
    {
        return $query->where('entry_type', self::TYPE_CHARGE);
    }

    public function scopePayments(Builder $query): Builder
    {
        return $query->where('entry_type', self::TYPE_PAYMENT);
    }
}
