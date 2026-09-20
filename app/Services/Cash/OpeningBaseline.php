<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\PhysicalStockCount;
use App\Models\Store;
use App\Models\StoreAccountClose;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Which count a branch's next period opens on.
 *
 * One definition, shared by the screen that shows the opening and the action
 * that freezes it. Two copies of this rule would be two answers to "what did
 * this period start with", and the gap between them is exactly where stock goes
 * missing unnoticed.
 *
 * The first close at a branch is the one time this is a choice, and it is a
 * choice because there is genuinely nothing to inherit. Every close after that
 * carries the previous closing count forward without asking: an opening
 * somebody can pick is an opening somebody can pick to suit the answer.
 */
final class OpeningBaseline
{
    private function __construct(
        public readonly string $source,
        public readonly ?PhysicalStockCount $count,
        public readonly ?StoreAccountClose $previousClose,
    ) {}

    /**
     * @param  ?PhysicalStockCount  $selected  What the user picked, which is
     *   only ever honoured on a first close. Passing one when a prior close
     *   exists is refused rather than ignored — a caller passing it believes it
     *   will be used, and silently substituting would break the chain in the
     *   one place nobody would think to look.
     */
    public static function resolve(Store $store, ?PhysicalStockCount $selected = null): self
    {
        $previous = StoreAccountClose::latestFor($store->id);

        if ($previous) {
            if ($selected && (int) $selected->id !== (int) $previous->closing_count_id) {
                throw new RuntimeException('This period opens on the last close\'s closing count. There is nothing to choose.');
            }

            return new self(
                StoreAccountClose::OPENING_CARRIED,
                $previous->closingCount,
                $previous,
            );
        }

        if ($selected && (int) $selected->store_id !== (int) $store->id) {
            throw new RuntimeException('That opening count was taken at another branch.');
        }

        // Null is allowed only here: a branch opening its first period having
        // never held stock opens on nothing, which is a true zero rather than a
        // missing answer. Every later period inherits a real count.
        return new self(StoreAccountClose::OPENING_SELECTED, $selected, null);
    }

    /**
     * The per-product stock the period opens with.
     *
     * Read from what was COUNTED, never the system figure frozen beside it. The
     * counted number is what a person standing at the shelf wrote down; the
     * system figure is the thing it was there to check. Approving a count also
     * corrects stock, so reading the system side would make the opening depend
     * on whether anybody had got round to signing off yet.
     *
     * @return Collection<int, int> product id => units
     */
    public function quantities(): Collection
    {
        if (! $this->count) {
            return collect();
        }

        return $this->count->lines()
            ->pluck('counted_quantity', 'product_id')
            ->map(fn ($q) => (int) $q);
    }

    public function isCarriedForward(): bool
    {
        return $this->source === StoreAccountClose::OPENING_CARRIED;
    }
}
