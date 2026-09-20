<?php

declare(strict_types=1);

namespace App\Actions\Cash;

use App\Models\PhysicalStockCount;
use App\Models\Store;
use App\Models\StoreAccountClose;
use App\Models\User;
use App\Services\Auth\StorePermission;
use App\Services\Cash\OpeningBaseline;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Close a branch's books for a period.
 *
 * This owns two things and deliberately not a third. It owns the chain — which
 * count a period opens on, and the refusal to let anybody choose that once a
 * prior close exists — and it owns the freeze. It does not own the figures:
 * they arrive already computed, because the screen, the printable statement and
 * this row have to show the same numbers, and the only way to guarantee that is
 * for all three to read one service rather than three calculations that agree
 * today.
 *
 * Nothing is locked by closing. Sales stay append-only, the arbitrary-range
 * statement stays live, and a correction dated inside a closed period still
 * lands — it simply will not move the figures somebody already signed.
 */
class CloseStoreAccountAction
{
    /**
     * @param  array<string, mixed>  $figures  The assembled balance, as
     *   StoreAccountCloseBalance produces it. Copied in whole and never
     *   recomputed; the paths lifted into columns below are the contract
     *   between that service and this row.
     */
    public function execute(
        User $closedBy,
        Store $store,
        CarbonInterface $from,
        CarbonInterface $to,
        PhysicalStockCount $closingCount,
        array $figures,
        ?PhysicalStockCount $openingCount = null,
    ): StoreAccountClose {
        // Enforced here rather than only on the button. Closing a period is a
        // different power from confirming cash and from editing a sale, and
        // this action is the only way to exercise it — a check that lives in
        // the UI is a check that a second caller does not have.
        StorePermission::authorize($closedBy, (int) $store->vendor_id, (int) $store->id, 'close_store_period');

        if ($from >= $to) {
            throw new RuntimeException('A period has to start before it ends.');
        }

        if ((int) $closingCount->store_id !== (int) $store->id) {
            throw new RuntimeException('That stock count was taken at another branch.');
        }

        return DB::transaction(function () use ($closedBy, $store, $from, $to, $closingCount, $figures, $openingCount) {
            // Serialises closes at this branch. Two people closing the same
            // period at once would otherwise both read "no prior close" and
            // fork the chain; the unique indexes would catch it, but as a
            // constraint violation rather than an answer.
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();

            // Checked here as well as in the unique index, so somebody who
            // picks last period's count gets told what is wrong rather than a
            // constraint violation. The index is what makes it true under a
            // race; this is what makes it legible.
            if (StoreAccountClose::where('closing_count_id', $closingCount->id)->exists()) {
                throw new RuntimeException('That count has already closed a period. Take a fresh one for the closing position.');
            }

            $baseline = OpeningBaseline::resolve($store, $openingCount);
            $previous = $baseline->previousClose;

            if ($previous && $from < $previous->period_to) {
                throw new RuntimeException(sprintf(
                    'This branch is closed up to %s. A new period has to start there or later.',
                    $previous->period_to->toDateString(),
                ));
            }

            if ($baseline->count && (int) $baseline->count->id === (int) $closingCount->id) {
                throw new RuntimeException('A period cannot open and close on the same count — nothing would have moved between them.');
            }

            $close = StoreAccountClose::create([
                'vendor_id'         => $store->vendor_id,
                'store_id'          => $store->id,
                'period_from'       => $from,
                'period_to'         => $to,
                'previous_close_id' => $previous?->id,
                'opening_source'    => $baseline->source,
                'opening_count_id'  => $baseline->count?->id,
                'closing_count_id'  => $closingCount->id,
                'payload'           => $figures,

                // Copies for the list screen, never a second calculation. Every
                // one of these is also in the payload, which stays definitive.
                'value_sold'          => (float) data_get($figures, 'balance.value_sold', 0),
                'submitted_total'     => (float) data_get($figures, 'balance.submitted_total', 0),
                'till_expenses'       => (float) data_get($figures, 'balance.till_expenses', 0),
                'period_debt'         => (float) data_get($figures, 'balance.period_debt', 0),
                'shortage'            => (float) data_get($figures, 'balance.shortage', 0),
                'variance_at_selling' => (float) data_get($figures, 'count_variance.at_selling', 0),

                'closed_by' => $closedBy->id,
                'closed_at' => now(),
            ]);

            return $close->refresh();
        });
    }
}
