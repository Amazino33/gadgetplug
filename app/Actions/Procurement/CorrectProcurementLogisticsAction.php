<?php

namespace App\Actions\Procurement;

use App\Models\Procurement;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Fixes a wrong trip logistics_cost entered on an ALREADY-reconciled
// procurement, without ever moving status back to draft or awaiting_logistics
// ("never re-open a reconciled procurement" — reconciliation stays a one-way
// door; this recalculates in place instead of reopening it). Recomputes
// every line and re-applies the same cost/price rules as reconcile (respects
// price_overridden), and leaves reconciled_at/logistics_recorded_by from the
// original reconciliation untouched — the activity log is the audit trail
// for who corrected what and when, not those columns.
class CorrectProcurementLogisticsAction
{
    public function __construct(private readonly PricingService $pricingService) {}

    public function execute(Procurement $procurement, float $newLogisticsCost): void
    {
        if (! $procurement->isReconciled()) {
            throw new RuntimeException('Only already-reconciled procurements can be corrected — use Reconcile for one still awaiting logistics.');
        }

        DB::transaction(function () use ($procurement, $newLogisticsCost) {
            $oldLogisticsCost = $procurement->logistics_cost;
            $procurement->update(['logistics_cost' => $newLogisticsCost]);

            $prices = $this->pricingService->priceTrip($procurement);

            foreach ($procurement->items()->with('product')->get() as $item) {
                $computed = $prices[$item->id];

                $item->update([
                    'landed_unit_cost' => $computed['landed_unit_cost'],
                    'suggested_price' => $computed['suggested_price'],
                ]);

                $product = $item->product;
                $oldPrice = (float) $product->price;
                $oldCostPrice = $product->cost_price !== null ? (float) $product->cost_price : null;

                $productUpdates = ['cost_price' => $computed['landed_unit_cost']];
                if (! $product->price_overridden) {
                    $productUpdates['price'] = $computed['suggested_price'];
                }
                $product->update($productUpdates);

                activity()
                    ->causedBy(auth()->user())
                    ->performedOn($product)
                    ->withProperties([
                        'procurement_id' => $procurement->id,
                        'trigger' => 'logistics_correction',
                        'old_logistics_cost' => $oldLogisticsCost,
                        'new_logistics_cost' => $newLogisticsCost,
                        'old_cost_price' => $oldCostPrice,
                        'new_cost_price' => $computed['landed_unit_cost'],
                        'old_price' => $oldPrice,
                        'new_price' => (float) $product->price,
                        'price_overridden' => $product->price_overridden,
                    ])
                    ->tap(fn ($a) => $a->vendor_id = $procurement->vendor_id)
                    ->log($product->price_overridden
                        ? 'Corrected procurement logistics cost — selling price kept (manually overridden), margin delta recorded'
                        : 'Corrected procurement logistics cost — cost and selling price recalculated');
            }

            activity()
                ->causedBy(auth()->user())
                ->performedOn($procurement)
                ->withProperties([
                    'old_logistics_cost' => $oldLogisticsCost,
                    'new_logistics_cost' => $newLogisticsCost,
                ])
                ->tap(fn ($a) => $a->vendor_id = $procurement->vendor_id)
                ->log('Logistics cost corrected on a reconciled procurement');
        });
    }
}
