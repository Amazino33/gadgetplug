<?php

namespace App\Models;

use App\Support\Pos\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A cashier's trading day at one counter: one cashier, one drawer, one
 * Moniepoint terminal, one date.
 *
 * The single shift primitive. It used to be only "which sales belong together",
 * opened whenever a till found no cached session and force-closed whenever
 * another one opened — which is how a session in this database stayed open for
 * eighteen days and no Z-report was ever produced. It is now opened with a
 * counted float and closed with a counted drawer, and the reconciliation lives
 * on it rather than in a parallel table saying the same things differently.
 *
 * Not append-only — a day legitimately moves open → pending_review → approved.
 * What is immutable is the evidence: once the counts are submitted, neither they
 * nor the figures they were measured against can change. Enforced here rather
 * than left to controllers, because these numbers decide whether a named person
 * is accused of being short.
 */
class PosSession extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'business_date'     => 'date',
        'opening_float'     => 'decimal:2',
        'closing_float'     => 'decimal:2',
        'counted_cash'      => 'decimal:2',
        'counted_terminal'  => 'decimal:2',
        'expected_cash'     => 'decimal:2',
        'expected_terminal' => 'decimal:2',
        'cash_variance'     => 'decimal:2',
        'terminal_variance' => 'decimal:2',
        'breakdown'         => 'array',
        'opened_at'         => 'datetime',
        'closed_at'         => 'datetime',
        'reviewed_at'       => 'datetime',
    ];

    /** Trading. Counting has not happened yet. */
    public const STATUS_OPEN = 'open';

    /** Counted and submitted. Waiting on a manager, but blocking nothing. */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    /** A manager or owner has seen both legs and signed it off. */
    public const STATUS_APPROVED = 'approved';

    /**
     * Sessions retired before cash-up existed, and the ones the old open path
     * force-closed without ever counting. Nothing writes this any more.
     */
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
    ];

    /**
     * Fields frozen the moment counts are submitted.
     *
     * The counted figures are the cashier's word, and the expected figures plus
     * their breakdown are what the server held them to; a variance whose inputs
     * can move afterwards proves nothing.
     */
    public const EVIDENCE_FIELDS = [
        'opening_float',
        'counted_cash',
        'counted_terminal',
        'expected_cash',
        'expected_terminal',
        'cash_variance',
        'terminal_variance',
        'breakdown',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            if (! in_array($session->status ?? self::STATUS_OPEN, self::STATUSES, true)) {
                throw new LogicException('PosSession status must be one of: '.implode(', ', self::STATUSES).'.');
            }

            // The column defaults to zero, which is exactly how every variance
            // came to be wrong by whatever was in the drawer at the start. A day
            // without a counted float cannot be reconciled, so it cannot open.
            if ($session->opening_float === null) {
                throw new LogicException('A till session cannot open without a counted opening float.');
            }
        });

        static::updating(function (self $session) {
            if (! in_array($session->status, self::STATUSES, true)) {
                throw new LogicException('PosSession status must be one of: '.implode(', ', self::STATUSES).'.');
            }

            // A counted day's evidence is fixed. Recomputing it later would
            // quietly rewrite what a cashier was shown and held to.
            if (! in_array($session->getOriginal('status'), [self::STATUS_OPEN, self::STATUS_CLOSED], true)) {
                foreach (self::EVIDENCE_FIELDS as $field) {
                    if ($session->isDirty($field)) {
                        throw new LogicException(
                            "PosSession {$field} is frozen once counts are submitted. Append a rectification instead of rewriting the evidence."
                        );
                    }
                }
            }

            // Approval is terminal. Reopening it would let a signed-off day be
            // reworked with nobody named as having done so.
            if ($session->getOriginal('status') === self::STATUS_APPROVED
                && $session->status !== self::STATUS_APPROVED) {
                throw new LogicException('An approved cash-up cannot be reopened. Post a correcting ledger entry instead.');
            }
        });
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Whether the counts are in, and therefore whether figures may be revealed. */
    public function countsSubmitted(): bool
    {
        return $this->counted_cash !== null && $this->counted_terminal !== null;
    }

    // ── Variance: frozen, then explained ─────────────────────────────────────
    //
    // Two different figures, both true. cash_variance is frozen at close: the
    // gap exactly as it was first put to the cashier, before anybody explained
    // any of it. What remains unexplained moves, because only a manager may
    // rectify and they do it afterwards — so it is derived on read, never
    // stored, for the same reason no balance in this codebase is stored.

    public function resolvedCashVariance(): float
    {
        return $this->resolvedVariance(CashUpRectification::LEG_CASH, (float) $this->cash_variance);
    }

    public function resolvedTerminalVariance(): float
    {
        return $this->resolvedVariance(CashUpRectification::LEG_TERMINAL, (float) $this->terminal_variance);
    }

    private function resolvedVariance(string $leg, float $frozen): float
    {
        $explained = $this->rectifications
            ->sum(fn (CashUpRectification $entry) => $entry->effectOn($leg));

        return round($frozen - $explained, 2);
    }

    /** Nothing left to argue about on either leg. */
    public function isFullyExplained(): bool
    {
        return abs($this->resolvedCashVariance()) < 0.01
            && abs($this->resolvedTerminalVariance()) < 0.01;
    }

    /**
     * The two legs disagreeing in opposite directions is the wrong-tender
     * signature — a sale rung on the wrong button.
     *
     * Read off the resolved figures, so the hint stops being offered the moment
     * the manager posts the reclass it was suggesting.
     */
    public function legsOffsetEachOther(): bool
    {
        if (! $this->countsSubmitted()) {
            return false;
        }

        $cash = $this->resolvedCashVariance();
        $terminal = $this->resolvedTerminalVariance();

        return abs($cash) > 0.009
            && abs($terminal) > 0.009
            && ($cash < 0) !== ($terminal < 0)
            && abs($cash + $terminal) < 0.01;
    }

    /** The frozen gap across both legs, as first presented. */
    public function netVariance(): float
    {
        return round((float) $this->cash_variance + (float) $this->terminal_variance, 2);
    }

    /** What is still missing across both legs, after everything explained. */
    public function netResolvedVariance(): float
    {
        return round($this->resolvedCashVariance() + $this->resolvedTerminalVariance(), 2);
    }

    /** Only a manager rectifies, and only while the day is awaiting review. */
    public function acceptsRectifications(): bool
    {
        return $this->isPendingReview();
    }

    // ── Blind entry ──────────────────────────────────────────────────────────

    /**
     * The session as the till may see it.
     *
     * The cashier counts before any expected figure is revealed, so the server
     * must never hand those back before the counts land. Enforced here, on the
     * model, rather than trusted to each endpoint — one forgotten field in one
     * controller would defeat the entire control.
     *
     * @return array<string, mixed>
     */
    public function toBlindArray(): array
    {
        $payload = $this->toArray();

        if ($this->countsSubmitted()) {
            return $payload;
        }

        foreach (['expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance', 'breakdown'] as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The branch this till stands in.
     *
     * Nullable: sessions opened before multi-store existed have none, and the
     * unique key treats each NULL as distinct — which is the right answer for a
     * row that cannot be placed at a branch anyway.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function rectifications(): HasMany
    {
        return $this->hasMany(CashUpRectification::class, 'pos_session_id');
    }

    public function zReport(): HasOne
    {
        return $this->hasOne(PosZReport::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeForCashier(Builder $query, int $cashierId): Builder
    {
        return $query->where('cashier_id', $cashierId);
    }

    public function scopeForDate(Builder $query, string $businessDate): Builder
    {
        return $query->whereDate('business_date', $businessDate);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_REVIEW);
    }

    /**
     * The one session a cashier may have in progress at a branch today.
     *
     * The unique key on (cashier_id, store_id, business_date) is the real
     * guarantee — two retried opens can arrive at once, and only the database
     * can settle that. This is the read side of the same rule.
     */
    public static function openFor(int $cashierId, int $storeId, ?string $businessDate = null): ?self
    {
        return static::query()
            ->forCashier($cashierId)
            ->forStore($storeId)
            ->forDate($businessDate ?? BusinessDate::today())
            ->open()
            ->first();
    }

    /** Any session for this cashier/branch/day, whatever its status. */
    public static function forDay(int $cashierId, int $storeId, ?string $businessDate = null): ?self
    {
        return static::query()
            ->forCashier($cashierId)
            ->forStore($storeId)
            ->forDate($businessDate ?? BusinessDate::today())
            ->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 'cashier_id', 'store_id', 'terminal_id', 'opening_float',
                'counted_cash', 'counted_terminal', 'expected_cash', 'expected_terminal',
                'cash_variance', 'terminal_variance', 'reviewed_by', 'opened_at', 'closed_at',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Till session '.$event);
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->vendor_id = $this->vendor_id;
    }
}
