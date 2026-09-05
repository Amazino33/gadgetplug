<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment, a return, or a write-off against one picking line.
 *
 * Append-only. What a picker still holds and still owes is always the sum of
 * these rows — there is no balance column anywhere, so a balance can never
 * drift from the history that produced it.
 */
class PickingLedgerEntry extends Model
{
    protected $guarded = [];

    // No update path exists, so an updated_at column would only ever hold a
    // duplicate of created_at. Same reasoning as PosCustomerLedgerEntry.
    public const UPDATED_AT = null;

    protected $casts = [
        'quantity'   => 'integer',
        'amount'     => 'decimal:2',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** Money handed over for goods the picker has sold. */
    public const DIRECTION_PAYMENT = 'payment';

    /** Goods brought back unsold, returned to the shelf. */
    public const DIRECTION_RETURN = 'return';

    /** The owner's decision to stop chasing goods that will not come back. */
    public const DIRECTION_WRITEOFF = 'writeoff';

    public const DIRECTIONS = [
        self::DIRECTION_PAYMENT,
        self::DIRECTION_RETURN,
        self::DIRECTION_WRITEOFF,
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PickingItem::class, 'picking_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
