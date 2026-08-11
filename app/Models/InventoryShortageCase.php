<?php

namespace App\Models;

use App\Support\Accountability\FrozenLossSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A shortage awaiting the owner's decision. Mutable, unlike the ledger — a case
// is a workflow record that legitimately changes status, whereas a ledger entry
// is a statement of fact that must not. The frozen snapshot columns are the
// exception: they are copied in at open and nothing here ever rewrites them.
class InventoryShortageCase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'shortage_qty'        => 'integer',
        'unit_cost_snapshot'  => 'decimal:2',
        'unit_price_snapshot' => 'decimal:2',
        'charge_amount'       => 'decimal:2',
        'cost_component'      => 'decimal:2',
        'margin_component'    => 'decimal:2',
        'price_fallback'      => 'boolean',
        'disposed_at'         => 'datetime',
    ];

    /** Gate these behind view_cost_price — see ProductForm::canSeeCostPrice(). */
    public const COST_SENSITIVE_FIELDS = [
        'unit_cost_snapshot',
        'cost_component',
        'margin_component',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function countLine(): BelongsTo
    {
        return $this->belongsTo(AuditSession::class, 'count_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function chargedStorekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'charged_storekeeper_id');
    }

    public function disposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_disposition';
    }

    public function isInvestigating(): bool
    {
        return $this->status === 'investigating';
    }

    /**
     * Still open to a decision. A charged or written-off case is finished —
     * reopening it would mean rewriting a financial fact.
     */
    public function awaitsDisposition(): bool
    {
        return in_array($this->status, ['pending_disposition', 'investigating'], true);
    }

    /** Rebuild the frozen split without touching the product. */
    public function snapshot(): FrozenLossSnapshot
    {
        return FrozenLossSnapshot::fromFrozen(
            shortageQty: (int) $this->shortage_qty,
            unitCostSnapshot: (float) $this->unit_cost_snapshot,
            unitPriceSnapshot: (float) $this->unit_price_snapshot,
            priceFallback: (bool) $this->price_fallback,
        );
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeAwaitingDisposition(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending_disposition', 'investigating']);
    }
}
