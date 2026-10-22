<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

// What the customer did once: one basket, one address, one payment, one
// reference. The goods are fulfilled by one child Order per vendor.
class Checkout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items_total'            => 'decimal:2',
            'delivery_fee'           => 'decimal:2',
            'grand_total'            => 'decimal:2',
            'delivery_rule_snapshot' => 'array',
            'purchase_tracked_at'    => 'datetime',
            // The customer's optional shared location. Staff and rider only.
            'customer_latitude'      => 'float',
            'customer_longitude'     => 'float',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Resolve whatever reference a customer has: the checkout's own, or a
     * child order's (including every historical order, which was backfilled
     * 1:1 under its own reference).
     */
    public static function findByReference(string $reference): ?self
    {
        return static::where('reference', $reference)->first()
            ?? Order::where('reference', $reference)->first()?->checkout;
    }

    /** The LGA delivered to. */
    public function lga(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /**
     * One status to show the customer for the whole checkout: its orders'
     * status when they agree, otherwise 'in_progress' (some parts further along
     * than others — /track shows each).
     */
    public function summaryStatus(): string
    {
        $statuses = $this->orders->pluck('status')->unique();

        return $statuses->count() === 1 ? (string) $statuses->first() : 'in_progress';
    }

    /**
     * Claim the once-per-checkout Purchase conversion. Atomic, so two child
     * orders confirming in the same moment cannot both send it.
     */
    public function claimPurchaseTracking(): bool
    {
        return static::whereKey($this->id)
            ->whereNull('purchase_tracked_at')
            ->update(['purchase_tracked_at' => now()]) === 1;
    }

    /**
     * Claim the one customer confirmation for this checkout, atomically, so a
     * checkout split across vendors confirms once — not once per part.
     */
    public function claimCustomerConfirmation(): bool
    {
        return static::whereKey($this->id)
            ->whereNull('customer_confirmation_sent_at')
            ->update(['customer_confirmation_sent_at' => now()]) === 1;
    }
}
