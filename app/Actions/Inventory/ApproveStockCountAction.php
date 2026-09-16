<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\PhysicalStockCount;
use App\Models\User;
use App\Services\AccountabilityLedger;
use App\Services\Auth\StorePermission;
use App\Support\Accountability\FrozenLossSnapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sign off a count, bring the books into line, and say where the loss went.
 *
 * The two halves are one transaction on purpose. Correcting stock without
 * recording the gap would make approving a count the cheapest way to erase a
 * theft — quieter than voiding a sale, because nothing anywhere would say it
 * happened. So the books only ever move at the same moment somebody's name goes
 * against what was lost, or the business formally absorbs it.
 *
 * The adjustment is applied as the variance recorded at counting time, never as
 * "set stock to the counted number". Sales keep happening between counting and
 * approval, and setting an absolute would silently reverse them.
 */
class ApproveStockCountAction
{
    public function __construct(
        private readonly AdjustStockAction $adjustStock,
        private readonly AccountabilityLedger $ledger,
    ) {}

    public function approve(
        PhysicalStockCount $count,
        User $approver,
        string $outcome,
        ?User $chargeTo = null,
        ?string $note = null,
    ): PhysicalStockCount {
        $this->guard($count, $approver);

        if (! in_array($outcome, [
            PhysicalStockCount::OUTCOME_WRITTEN_OFF,
            PhysicalStockCount::OUTCOME_CHARGED,
            PhysicalStockCount::OUTCOME_NONE,
        ], true)) {
            throw new RuntimeException('Say what happened to the missing stock.');
        }

        if ($outcome === PhysicalStockCount::OUTCOME_CHARGED && ! $chargeTo) {
            throw new RuntimeException('Name the person the shortage is being charged to.');
        }

        return DB::transaction(function () use ($count, $approver, $outcome, $chargeTo, $note) {
            $row = PhysicalStockCount::whereKey($count->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isSubmitted()) {
                throw new RuntimeException('That count has already been answered.');
            }

            $row->load('lines');
            $variance = $row->variance();

            // Ordered by product id for the same reason a sale is: these row
            // locks can deadlock against a concurrent till otherwise.
            foreach ($row->lines->sortBy('product_id') as $line) {
                $delta = $line->counted_quantity - $line->system_quantity;

                if ($delta === 0) {
                    continue;
                }

                $this->adjustStock->execute(
                    productId: $line->product_id,
                    quantityChanged: $delta,
                    transactionType: 'count_adjustment',
                    userId: $approver->id,
                    reference: 'COUNT-' . $row->id,
                    description: sprintf(
                        'Stock count %s — books %d, counted %d',
                        $delta < 0 ? 'shortfall' : 'overage',
                        $line->system_quantity,
                        $line->counted_quantity,
                    ),
                    store: $row->store_id,
                );
            }

            // The gap put to a named person, in the same ledger a blind-count
            // audit uses — so "who owes the business what" has one home rather
            // than one per feature.
            //
            // One charge per product, priced from what the count froze rather
            // than from the product today. A price that moved between counting
            // and approval must not change what somebody is asked to pay.
            if ($outcome === PhysicalStockCount::OUTCOME_CHARGED) {
                foreach ($row->lines as $line) {
                    $missing = $line->variance();

                    if ($missing <= 0) {
                        continue;
                    }

                    $unitCost = (float) ($line->unit_cost ?? 0);
                    $unitPrice = (float) ($line->unit_price ?? 0);

                    // No usable retail price: charge at cost so the business
                    // recovers what it paid, and flag why.
                    $priceFallback = $unitPrice <= 0.0;

                    $this->ledger->postChargeFromSnapshot(
                        vendorId: $row->vendor_id,
                        snapshot: FrozenLossSnapshot::fromFrozen(
                            shortageQty: $missing,
                            unitCostSnapshot: $unitCost,
                            unitPriceSnapshot: $priceFallback ? $unitCost : $unitPrice,
                            priceFallback: $priceFallback,
                        ),
                        // Idempotent per count per product, so a retried
                        // approval cannot charge the same person twice.
                        naturalKey: "charge:settlement-count:{$row->id}:product:{$line->product_id}",
                        storekeeperId: $chargeTo->id,
                        createdBy: $approver->id,
                        note: $note ?: 'Missing at stock count',
                        storeId: $row->store_id,
                        sourceType: PhysicalStockCount::class,
                        sourceId: $row->id,
                    );
                }
            }

            $row->update([
                'status'        => PhysicalStockCount::STATUS_APPROVED,
                'approved_by'   => $approver->id,
                'approved_at'   => now(),
                'outcome'       => $variance['units'] === 0 ? PhysicalStockCount::OUTCOME_NONE : $outcome,
                'charged_to'    => $outcome === PhysicalStockCount::OUTCOME_CHARGED ? $chargeTo?->id : null,
                'decision_note' => $note,
                'adjusted_at'   => now(),
            ]);

            return $row->fresh(['lines', 'approvedBy', 'chargedTo']);
        });
    }

    /**
     * Not accepted — usually a recount.
     *
     * The counted figures stay on the record either way. A count somebody
     * disagreed with is evidence of the disagreement, and deleting it would
     * leave no trace that a number was ever questioned.
     */
    public function reject(PhysicalStockCount $count, User $approver, string $note): PhysicalStockCount
    {
        $this->guard($count, $approver);

        if (blank($note)) {
            throw new RuntimeException('Say why the count is not accepted.');
        }

        return DB::transaction(function () use ($count, $approver, $note) {
            $row = PhysicalStockCount::whereKey($count->getKey())->lockForUpdate()->firstOrFail();

            if (! $row->isSubmitted()) {
                throw new RuntimeException('That count has already been answered.');
            }

            $row->update([
                'status'        => PhysicalStockCount::STATUS_REJECTED,
                'approved_by'   => $approver->id,
                'approved_at'   => now(),
                'decision_note' => $note,
            ]);

            return $row->fresh();
        });
    }

    private function guard(PhysicalStockCount $count, User $approver): void
    {
        // The rule the whole arrangement rests on. Whoever counted the shelf is
        // usually the person a shortage would be put to, and letting them sign
        // it off themselves would make the count worth nothing.
        if ((int) $count->counted_by === (int) $approver->id) {
            throw new RuntimeException('You cannot approve a count you took yourself.');
        }

        if (! StorePermission::allows($approver, (int) $count->vendor_id, (int) $count->store_id, 'approve_stock_count')) {
            throw new RuntimeException('You are not permitted to approve counts for this branch.');
        }
    }
}
