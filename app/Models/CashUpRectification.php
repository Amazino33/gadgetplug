<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The explained part of a difference, appended and never edited.
 *
 * Same discipline as AccountabilityLedgerEntry and PosCustomerLedgerEntry: a
 * mistake is corrected by appending an opposing row. These rows are a cashier's
 * account of money that is not in the drawer, and one that could be quietly
 * rewritten afterwards would be worth nothing to the person relying on it.
 *
 * Every kind is the same arithmetic underneath — an amount leaving one tender's
 * expected figure and arriving at another's — which is why effectOn() has no
 * match on kind. The kinds exist for the human reading the review screen.
 */
class CashUpRectification extends Model
{
    protected $guarded = [];

    // No update path exists, so updated_at would only ever duplicate created_at.
    public const UPDATED_AT = null;

    protected $casts = [
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** Money spent out of the drawer — transport, a delivery, change for a neighbour. */
    public const KIND_EXPENSE = 'expense';

    /** Cash handed to someone else, so it is out of this drawer but still in the business. */
    public const KIND_CASH_OUT = 'cash_out';

    /** Rung on the wrong button: the money came in on a different tender than recorded. */
    public const KIND_TENDER_RECLASS = 'tender_reclass';

    /** Rung as credit but actually paid there and then. */
    public const KIND_DEBT_PAID = 'debt_paid';

    public const KINDS = [
        self::KIND_EXPENSE,
        self::KIND_CASH_OUT,
        self::KIND_TENDER_RECLASS,
        self::KIND_DEBT_PAID,
    ];

    /** Kinds that correct a specific sale, and therefore flag it. */
    public const SALE_CORRECTING_KINDS = [
        self::KIND_TENDER_RECLASS,
        self::KIND_DEBT_PAID,
    ];

    /** Kinds that take money out of the drawer and out of this reconciliation. */
    public const CASH_OUTFLOW_KINDS = [
        self::KIND_EXPENSE,
        self::KIND_CASH_OUT,
    ];

    /** The cash leg. */
    public const CASH_TENDERS = ['cash'];

    /**
     * The terminal leg. Both card and transfer go through the cashier's own
     * Moniepoint terminal, so both read off the same screen total.
     */
    public const TERMINAL_TENDERS = ['card', 'bank_transfer'];

    /**
     * Debt is a tender the till accepts but it is money in nobody's hands, so it
     * belongs to neither leg. That is exactly why a debt_paid raises one leg
     * without lowering the other.
     */
    public const TENDERS = ['cash', 'card', 'bank_transfer', 'debt'];

    public const LEG_CASH = 'cash';
    public const LEG_TERMINAL = 'terminal';

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! in_array($entry->kind, self::KINDS, true)) {
                throw new LogicException('CashUpRectification kind must be one of: '.implode(', ', self::KINDS).'.');
            }

            // The sign convention lives in from_tender/to_tender, so a negative
            // amount here would mean the same movement twice over.
            if ((float) $entry->amount <= 0) {
                throw new LogicException('A rectification amount must be positive; direction is carried by from_tender and to_tender.');
            }

            foreach (['from_tender', 'to_tender'] as $field) {
                if (filled($entry->{$field}) && ! in_array($entry->{$field}, self::TENDERS, true)) {
                    throw new LogicException("CashUpRectification {$field} must be one of: ".implode(', ', self::TENDERS).'.');
                }
            }

            // Money out of the drawer always leaves cash and arrives nowhere. Set
            // here rather than trusted to the caller so effectOn() needs no
            // special case for these kinds.
            if (in_array($entry->kind, self::CASH_OUTFLOW_KINDS, true)) {
                $entry->from_tender = 'cash';
                $entry->to_tender = null;
            }

            if ($entry->kind === self::KIND_TENDER_RECLASS) {
                if (blank($entry->from_tender) || blank($entry->to_tender)) {
                    throw new LogicException('A tender reclass needs both the tender it was rung as and the one it was actually paid on.');
                }

                if ($entry->from_tender === $entry->to_tender) {
                    throw new LogicException('A tender reclass between the same tender moves nothing.');
                }
            }

            if ($entry->kind === self::KIND_DEBT_PAID) {
                // The amount comes off the debt ledger, which is what 'debt' as a
                // from_tender means here.
                $entry->from_tender = 'debt';

                if (blank($entry->to_tender) || $entry->to_tender === 'debt') {
                    throw new LogicException('A debt_paid needs the real tender the money was actually paid on.');
                }
            }

            if (in_array($entry->kind, self::SALE_CORRECTING_KINDS, true) && blank($entry->related_sale_id)) {
                throw new LogicException('A '.$entry->kind.' must name the sale it corrects, since that sale is what gets flagged.');
            }
        });

        static::updating(function () {
            throw new LogicException('CashUpRectification rows are append-only and can never be updated. Append an opposing entry instead.');
        });

        static::deleting(function () {
            throw new LogicException('CashUpRectification rows are append-only and can never be deleted. Append an opposing entry instead.');
        });
    }

    // ── Effects ──────────────────────────────────────────────────────────────

    /**
     * This entry's signed effect on one leg's expected figure.
     *
     * An amount arriving on a leg raises what should be there; an amount leaving
     * lowers it. A movement wholly inside one leg — card to transfer, say — nets
     * to zero, which is correct: both read off the same terminal screen.
     */
    public function effectOn(string $leg): float
    {
        $tenders = $leg === self::LEG_CASH ? self::CASH_TENDERS : self::TERMINAL_TENDERS;
        $amount = (float) $this->amount;

        $arriving = in_array($this->to_tender, $tenders, true) ? $amount : 0.0;
        $leaving = in_array($this->from_tender, $tenders, true) ? $amount : 0.0;

        return round($arriving - $leaving, 2);
    }

    public function cashEffect(): float
    {
        return $this->effectOn(self::LEG_CASH);
    }

    public function terminalEffect(): float
    {
        return $this->effectOn(self::LEG_TERMINAL);
    }

    /** Whether this entry flags a sale for correction. */
    public function flagsSale(): bool
    {
        return in_array($this->kind, self::SALE_CORRECTING_KINDS, true)
            && filled($this->related_sale_id);
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashUpSession::class, 'cash_up_session_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function relatedSale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'related_sale_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** The derived sale-correction flag: sales something is pointing at. */
    public function scopeFlaggingSale(Builder $query, int $saleId): Builder
    {
        return $query->where('related_sale_id', $saleId)
            ->whereIn('kind', self::SALE_CORRECTING_KINDS);
    }
}
