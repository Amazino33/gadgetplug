<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\AuditSession;
use App\Models\BlindCountSession;
use App\Models\PhysicalStockCount;
use App\Models\PhysicalStockCountLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Take a finished inventory count and make it usable as a settlement count.
 *
 * The blind count on the Inventory Count page is where stock actually gets
 * counted here — two people, neither seeing the other's figures. The account
 * close needs a single agreed number per product, and this is what turns one
 * into the other.
 *
 * It copies rather than reads through, for the same reason every other count in
 * this codebase is frozen at the moment it is taken: cost and selling price
 * move, and a close that re-read them later would quietly restate what a closed
 * period was holding. The copy is idempotent — adopting the same session twice
 * gives back the count made the first time rather than a second one competing
 * with it.
 *
 * What counts as the agreed figure, in order:
 *
 *   resolved_by_override — a manager looked at two disagreeing counts and said
 *     what the number is. That decision outranks both counters.
 *   verified — the two counters independently landed on the same number, which
 *     is the whole point of counting blind.
 *
 * A line still in discrepancy has no agreed figure and is deliberately left
 * out. Putting one counter's number in would assert something nobody agreed,
 * and the opening balance of every later period would inherit it.
 */
class AdoptAuditCountAction
{
    public function execute(BlindCountSession $session, User $actor): PhysicalStockCount
    {
        if ($session->status !== 'completed') {
            throw new RuntimeException('Only a finished inventory count can be used to close a period.');
        }

        if (! $session->store_id) {
            throw new RuntimeException('That inventory count is not tied to a branch, so it cannot close one.');
        }

        return DB::transaction(function () use ($session, $actor) {
            // Locked, so two people adopting at once cannot both pass the
            // existence check below and race to create competing counts.
            $existing = PhysicalStockCount::query()
                ->where('blind_count_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('lines');
            }

            $lines = AuditSession::query()
                ->where('blind_count_session_id', $session->id)
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('That inventory count produced no lines to read.');
            }

            $agreed = $lines
                ->map(fn (AuditSession $line) => [
                    'product_id' => (int) $line->product_id,
                    'counted'    => $this->agreedCount($line),
                    'system'     => (int) $line->system_quantity,
                ])
                ->filter(fn (array $row) => $row['counted'] !== null)
                ->values();

            if ($agreed->isEmpty()) {
                throw new RuntimeException('None of the lines in that count were agreed, so there is no figure to close on.');
            }

            $count = PhysicalStockCount::create([
                'vendor_id' => $session->vendor_id,
                'store_id'  => $session->store_id,
                // Whoever adopted it, not whoever counted: this record is the
                // act of bringing the count into the settlement, and the
                // counters are already named on the session it came from.
                'counted_by' => $actor->id,
                'blind_count_session_id' => $session->id,
                'period_start' => $session->created_at,
                'period_end'   => $session->updated_at,
                'counted_at'   => $session->updated_at,
                'note' => sprintf(
                    'Adopted from inventory count #%d. %d of %d lines agreed.',
                    $session->id,
                    $agreed->count(),
                    $lines->count(),
                ),
                'status' => PhysicalStockCount::STATUS_SUBMITTED,
            ]);

            // Frozen now, exactly as a hand-entered count freezes them. The
            // blind count never recorded either price, so this is the earliest
            // moment they can be captured — which makes them the price as at
            // adoption, not as at counting. Worth knowing when a count is
            // adopted weeks after it was taken.
            $products = Product::query()
                ->whereIn('id', $agreed->pluck('product_id'))
                ->get(['id', 'cost_price', 'price'])
                ->keyBy('id');

            foreach ($agreed as $row) {
                PhysicalStockCountLine::create([
                    'physical_stock_count_id' => $count->id,
                    'product_id'       => $row['product_id'],
                    'counted_quantity' => $row['counted'],
                    // The baseline the blind count itself froze, carried over
                    // rather than re-read, so the variance this count found is
                    // the variance it still reports.
                    'system_quantity'  => $row['system'],
                    'unit_cost'        => $products[$row['product_id']]?->cost_price,
                    'unit_price'       => $products[$row['product_id']]?->price,
                ]);
            }

            return $count->load('lines');
        });
    }

    /** The number both sides — or a manager — actually settled on. */
    private function agreedCount(AuditSession $line): ?int
    {
        if ($line->status === 'resolved_by_override' && $line->manager_override_count !== null) {
            return (int) $line->manager_override_count;
        }

        if ($line->status === 'verified') {
            return (int) $line->count_b;
        }

        // Still disagreed, or never counted. No figure exists to adopt.
        return null;
    }
}
