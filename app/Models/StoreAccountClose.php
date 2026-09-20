<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The books, closed on a branch for a period.
 *
 * Two things a settlement statement is not. A statement can be printed a dozen
 * times over the same dates and none of them ends anything; this ends a period
 * and names the stock the next one opens with.
 *
 * Immutable for the same reason every other accusing row in this codebase is:
 * these figures are what somebody signed, and a signature over numbers that can
 * be edited afterwards is worth nothing to the person it names.
 */
class StoreAccountClose extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'             => 'array',
        'period_from'         => 'datetime',
        'period_to'           => 'datetime',
        'closed_at'           => 'datetime',
        'value_sold'          => 'decimal:2',
        'submitted_total'     => 'decimal:2',
        'till_expenses'       => 'decimal:2',
        'period_debt'         => 'decimal:2',
        'shortage'            => 'decimal:2',
        'variance_at_selling' => 'decimal:2',
    ];

    /** Somebody chose which count seeds the opening. Only ever a first close. */
    public const OPENING_SELECTED = 'selected_count';

    /** The previous close's closing count, taken without asking. */
    public const OPENING_CARRIED = 'carried_forward';

    protected static function booted(): void
    {
        static::created(function (self $close) {
            $close->updateQuietly([
                'reference' => 'GP-CLOSE-' . str_pad((string) $close->id, 6, '0', STR_PAD_LEFT),
            ]);
        });

        static::updating(function (self $close) {
            // The reference is stamped from the id, which only exists once the
            // row does. Nothing else may ever change.
            $changing = array_values(array_diff(array_keys($close->getDirty()), ['updated_at']));

            if ($changing === ['reference'] && blank($close->getOriginal('reference'))) {
                return;
            }

            throw new LogicException('A close is what the books said when somebody signed them. Close a fresh period rather than editing one.');
        });

        static::deleting(function () {
            throw new LogicException('Closes are never deleted — the opening balance of every period after this one hangs off it.');
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function openingCount(): BelongsTo
    {
        return $this->belongsTo(PhysicalStockCount::class, 'opening_count_id');
    }

    public function closingCount(): BelongsTo
    {
        return $this->belongsTo(PhysicalStockCount::class, 'closing_count_id');
    }

    public function previousClose(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_close_id');
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * The close a branch's next period follows on from.
     *
     * Ordered by the period it covers rather than when somebody got round to
     * closing it — a period closed late is still the earlier period.
     */
    public static function latestFor(int $storeId): ?self
    {
        return self::query()
            ->forStore($storeId)
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->first();
    }

    /** No prior close to inherit an opening from, so one had to be chosen. */
    public function isFirstClose(): bool
    {
        return $this->previous_close_id === null;
    }

    /**
     * The per-product stock this period opened with.
     *
     * Read from what was COUNTED, never from the system figure beside it. The
     * counted number is the one a person stood in front of the shelf and wrote
     * down; the system figure is the thing it was there to check. Approving a
     * count also corrects stock, so reading the system side would make the
     * opening depend on whether somebody had got round to signing off yet.
     *
     * Empty on a first close at a branch that has never held stock, which is a
     * true opening of nothing rather than a missing answer.
     *
     * @return Collection<int, int> product id => units
     */
    public function openingQuantities(): Collection
    {
        return $this->quantitiesFrom($this->openingCount);
    }

    /**
     * The per-product stock this period closed with — and, by the chain, what
     * the next period opens with.
     *
     * @return Collection<int, int> product id => units
     */
    public function closingQuantities(): Collection
    {
        return $this->quantitiesFrom($this->closingCount);
    }

    /** Reach into the frozen figures without unpacking the whole payload. */
    public function figure(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }

    /** @return Collection<int, int> */
    private function quantitiesFrom(?PhysicalStockCount $count): Collection
    {
        if (! $count) {
            return collect();
        }

        return $count->lines()
            ->pluck('counted_quantity', 'product_id')
            ->map(fn ($q) => (int) $q);
    }
}
