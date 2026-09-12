<?php

namespace App\Models;

use App\Support\Pos\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One cashier, one drawer, one Moniepoint terminal, one trading day.
 *
 * Not append-only — a session legitimately moves open → pending_review →
 * approved. What is immutable is the evidence: once the cashier has submitted
 * their counts, neither the counts nor the expected figures they were measured
 * against can change. Enforced below rather than left to controllers, because
 * these numbers decide whether a named person is accused of being short.
 *
 * Scoping follows the convention Store documents: a plain vendor_id/store_id
 * filter applied deliberately at every call site, no global scope, because this
 * codebase has none and adding one here would make this the only model whose
 * queries silently mean something other than what they say.
 */
class CashUpSession extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'business_date'     => 'date',
        'opening_float'     => 'decimal:2',
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

    /** Counting has not happened yet. The cashier is still selling. */
    public const STATUS_OPEN = 'open';

    /** Counted and submitted. Waiting on a manager, but blocking nothing. */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    /** A manager or owner has seen both legs and signed it off. */
    public const STATUS_APPROVED = 'approved';

    public const STATUSES = [
        self::STATUS_OPEN,
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
                throw new LogicException('CashUpSession status must be one of: '.implode(', ', self::STATUSES).'.');
            }

            // Locked decision: without a float, the closing variance is measured
            // against an unknown, which is not a variance at all.
            if ($session->opening_float === null) {
                throw new LogicException('A cash-up session cannot open without an opening float.');
            }
        });

        static::updating(function (self $session) {
            if (! in_array($session->status, self::STATUSES, true)) {
                throw new LogicException('CashUpSession status must be one of: '.implode(', ', self::STATUSES).'.');
            }

            // A closed session's evidence is fixed. Recomputing it later would
            // quietly rewrite what a cashier was shown and held to — the same
            // reasoning cash_submissions.expected_amount is a snapshot.
            if ($session->getOriginal('status') !== self::STATUS_OPEN) {
                foreach (self::EVIDENCE_FIELDS as $field) {
                    if ($session->isDirty($field)) {
                        throw new LogicException(
                            "CashUpSession {$field} is frozen once counts are submitted. Append a rectification instead of rewriting the evidence."
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

    /** Whether the counts are in, and therefore whether expected figures may be revealed. */
    public function countsSubmitted(): bool
    {
        return $this->counted_cash !== null && $this->counted_terminal !== null;
    }

    // ── Variance: frozen, then explained ─────────────────────────────────────
    //
    // Two different figures, both true, and the distinction matters.
    //
    // cash_variance/terminal_variance are frozen at close: the gap exactly as it
    // was first put to the cashier, before anybody explained any of it. That
    // number is evidence and must never move.
    //
    // What remains unexplained does move, because only a manager may rectify and
    // they do it after the close. So it is derived on read from the frozen gap
    // and the rectifications appended since — never stored, for the same reason
    // no balance in this codebase is stored: a figure that can be written twice
    // can disagree with its own history.

    /**
     * The part of the cash gap still unaccounted for.
     *
     * A rectification lowers what the drawer should have held, so it closes the
     * gap by its own effect: short ₦5,000 with a ₦3,000 expense recorded leaves
     * ₦2,000 genuinely missing.
     */
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

        // Both materially non-zero, opposite signs, and cancelling to near nothing.
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

    /** Only a manager rectifies, and only while the cash-up is awaiting review. */
    public function acceptsRectifications(): bool
    {
        return $this->isPendingReview();
    }

    // ── Blind entry ──────────────────────────────────────────────────────────

    /**
     * The session as the till may see it.
     *
     * Locked decision: the cashier counts before any expected figure or variance
     * is revealed, so the server must never hand those back before the counts
     * land. Enforced here, on the model, rather than trusted to each endpoint —
     * one forgotten field in one controller would defeat the entire control.
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

    public function rectifications(): HasMany
    {
        return $this->hasMany(CashUpRectification::class, 'cash_up_session_id');
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
     * guarantee — two retried opens can arrive at once, and only the database can
     * settle that. This is the read side of the same rule.
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
                'status', 'opening_float', 'counted_cash', 'counted_terminal',
                'expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance',
                'reviewed_by',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => 'Cash-up '.$event);
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->vendor_id = $this->vendor_id;
    }
}
