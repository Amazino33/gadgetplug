<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PosSale extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * Open only while a reversal is being recorded.
     *
     * Every figure the reconciliation produces is a sum over these rows, so a
     * sale that can be edited after the fact makes the shortage it would have
     * shown disappear with it. The status column stays, because most of the
     * codebase reads it, but it is a mirror of pos_sale_reversals now — the
     * reversal is written first and this is what lets its mirror through.
     */
    private static bool $reversalMirrorOpen = false;

    /**
     * Columns that may still be stamped after the sale is rung.
     *
     * None of them is part of what was sold, what it was worth, or how it was
     * paid for, so none can move a figure on a settlement statement. The loyalty
     * stamp is set by the customer themselves opening their own receipt, long
     * after the sale — refusing it would break that for the sake of a rule about
     * money it does not touch.
     */
    private const STAMPABLE_AFTER_SALE = ['loyalty_claimed_at'];

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

        static::updating(function (self $sale) {
            // array_values because array_diff keeps the original keys, and a
            // dirty set that happened to list updated_at first would otherwise
            // leave ['status'] sitting at index 1 and fail the check below.
            $changing = array_values(array_diff(array_keys($sale->getDirty()), ['updated_at']));

            if ($changing === []) {
                return;
            }

            // A reversal has just been recorded and is bringing the mirror into
            // line with it. Nothing else may ride along on that write.
            if (self::$reversalMirrorOpen && $changing === ['status']) {
                return;
            }

            if (array_diff($changing, self::STAMPABLE_AFTER_SALE) === []) {
                return;
            }

            throw new LogicException(sprintf(
                'A rung sale is never edited (tried to change: %s). Record a reversal against it instead.',
                implode(', ', $changing) ?: 'nothing',
            ));
        });

        static::deleting(function (self $sale) {
            throw new LogicException('A rung sale is never deleted. Void it, so the withdrawal is on the record.');
        });
    }

    /**
     * Let a reversal bring the status mirror into line with itself.
     *
     * Intentionally awkward to reach: the only legitimate caller is
     * RecordSaleReversalAction, which writes the reversal row first. Anything
     * else changing a sale's status is the thing this guard exists to stop.
     *
     * @internal
     */
    public static function mirroringReversal(callable $callback): mixed
    {
        self::$reversalMirrorOpen = true;

        try {
            return $callback();
        } finally {
            self::$reversalMirrorOpen = false;
        }
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

    /**
     * Every withdrawal recorded against this sale, oldest first.
     *
     * The status column is derived from these. Read them when you need to know
     * why a sale stopped counting, or who decided it should.
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(PosSaleReversal::class)->oldest('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'original_sale_id');
    }

    /**
     * What the status column should say, worked out from the reversals alone.
     *
     * Exists so the mirror can be checked against its source — if these ever
     * disagree, something wrote to the sale outside the reversal path and the
     * reconciliation built on top of it cannot be trusted.
     */
    public function derivedStatus(): string
    {
        $latest = $this->reversals->last() ?? $this->reversals()->latest('id')->first();

        return $latest
            ? PosSaleReversal::STATUS_FOR_TYPE[$latest->type]
            : 'completed';
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
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
