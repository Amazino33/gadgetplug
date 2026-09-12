<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PosSale extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount'      => 'decimal:2',
        'total'           => 'decimal:2',
        'amount_tendered' => 'decimal:2',
        'change_given'    => 'decimal:2',
        'synced'          => 'boolean',
        'synced_at'       => 'datetime',
        'completed_at'    => 'datetime',
        'loyalty_claimed_at' => 'datetime',
    ];

    /**
     * Every sale gets the token its customer-facing copy is addressed by.
     *
     * Set here rather than at the call site so offline sales replayed through
     * the sync endpoint, and any future path that creates a sale, all get one —
     * a receipt printed without a token would carry a QR leading nowhere.
     */
    protected static function booted(): void
    {
        static::creating(function (self $sale) {
            if (blank($sale->public_token)) {
                $sale->public_token = static::generatePublicToken();
            }
        });
    }

    public static function generatePublicToken(): string
    {
        do {
            $token = \Illuminate\Support\Str::random(16);
        } while (static::where('public_token', $token)->exists());

        return $token;
    }

    /** The address a customer's QR opens. Null until the sale is saved. */
    public function publicUrl(): ?string
    {
        return $this->public_token ? route('receipt.public', $this->public_token) : null;
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * The branch that rang the sale.
     *
     * Nullable: sales predating multi-store were backfilled to the vendor's
     * default store by migration, but the column stays nullable, so anything
     * reading this must cope with none.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosSalePayment::class);
    }

    /**
     * Cash-up corrections aimed at this sale.
     *
     * The flag is these rows existing, not a column here. A sale rung on the
     * wrong tender keeps saying exactly what it was rung as — rewriting it would
     * destroy the only honest record of what happened at the counter — so the
     * correction sits beside it and "needs correcting" is derived, the same way
     * every balance in this codebase is.
     */
    public function correctionFlags(): HasMany
    {
        return $this->hasMany(CashUpRectification::class, 'related_sale_id')
            ->whereIn('kind', CashUpRectification::SALE_CORRECTING_KINDS);
    }

    /** Whether a cash-up has flagged this sale as rung wrongly. */
    public function isFlaggedForCorrection(): bool
    {
        return $this->correctionFlags()->exists();
    }

    public function isSplit(): bool
    {
        return $this->payment_method === 'split';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'status', 'total', 'discount_amount', 'discount_type', 'discount_approved_by', 'payment_method', 'cashier_id', 'customer_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Sale ' . $event);
    }
}
