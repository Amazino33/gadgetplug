<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A count of what is actually on the shelf, against what the system believed.
 *
 * The independent half of a settlement. Cash reconciliation can only check the
 * sales it was told about; goods that left without ever being rung leave the
 * cash side balancing perfectly. Counting the goods is the only thing that sees
 * them go.
 */
class PhysicalStockCount extends Model
{
    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = [
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
        'counted_at'   => 'datetime',
        'approved_at'  => 'datetime',
        'adjusted_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];

    /** Counted and waiting on somebody else to sign it off. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Signed off; the books have been brought into line and the gap recorded. */
    public const STATUS_APPROVED = 'approved';

    /** Not accepted — usually a recount. The figures stay on the record. */
    public const STATUS_REJECTED = 'rejected';

    /** The gap was absorbed by the business as shrinkage. */
    public const OUTCOME_WRITTEN_OFF = 'written_off';

    /** The gap was put to a named person, who now owes it. */
    public const OUTCOME_CHARGED = 'charged';

    /** Nothing was missing, so there was nothing to decide. */
    public const OUTCOME_NONE = 'none';

    /**
     * Columns the approval decision is allowed to set.
     *
     * Everything else — every counted figure, every system snapshot — is fixed
     * at the moment of counting. Approving decides what to do about the gap; it
     * can never quietly change what the gap was.
     */
    private const DECISION_COLUMNS = [
        'status', 'approved_by', 'approved_at', 'outcome',
        'charged_to', 'decision_note', 'adjusted_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $count) {
            $changing = array_values(array_diff(array_keys($count->getDirty()), ['updated_at']));

            if ($changing !== [] && array_diff($changing, self::DECISION_COLUMNS) === []) {
                return;
            }

            throw new LogicException('A count is what was found at a moment. Record another one rather than editing it.');
        });

        static::deleting(function () {
            throw new LogicException('Counts are never deleted — the variance they found would disappear with them.');
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function chargedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'charged_to');
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalStockCountLine::class);
    }

    /**
     * Units missing across every line, and what they were worth.
     *
     * Negative units mean more was found than the system expected, which is not
     * good news either — it usually means goods arrived without being received,
     * and the same gap that hides a theft hides that too.
     */
    public function variance(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $units = $lines->sum(fn (PhysicalStockCountLine $l) => $l->variance());
        $value = $lines->sum(fn (PhysicalStockCountLine $l) => $l->varianceValue());

        // Only what is MISSING is valued at selling price. Counting an overage
        // here would net it off and quietly shrink the figure that is supposed
        // to explain a cash gap — finding extra of one product does not mean
        // another was rung up.
        $atSelling = $lines
            ->filter(fn (PhysicalStockCountLine $l) => $l->variance() > 0)
            ->sum(fn (PhysicalStockCountLine $l) => $l->varianceAtSellingPrice());

        return [
            'units'             => (int) $units,
            'value'             => round((float) $value, 2),
            'missing_at_selling' => round((float) $atSelling, 2),
            'lines_short'       => $lines->filter(fn ($l) => $l->variance() > 0)->count(),
            'lines_over'        => $lines->filter(fn ($l) => $l->variance() < 0)->count(),
        ];
    }

    /**
     * The products that did not add up, worst first.
     *
     * A total tells somebody there is a problem; this tells them which shelf to
     * go and look at.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function discrepancies(): \Illuminate\Support\Collection
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->load('product');

        return $lines
            ->filter(fn (PhysicalStockCountLine $l) => $l->variance() !== 0)
            ->map(fn (PhysicalStockCountLine $l) => [
                'product_id'   => $l->product_id,
                'product'      => $l->product?->name ?? ('Product #' . $l->product_id),
                'system'       => $l->system_quantity,
                'counted'      => $l->counted_quantity,
                'missing'      => $l->variance(),
                'at_cost'      => $l->varianceValue(),
                'at_selling'   => $l->varianceAtSellingPrice(),
            ])
            ->sortByDesc('missing')
            ->values();
    }
}
