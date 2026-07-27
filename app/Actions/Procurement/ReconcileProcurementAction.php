<?php

namespace App\Actions\Procurement;

use App\Models\Procurement;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Logistics staff's half of the two-person workflow: awaiting_logistics ->
// reconciled. Recomputes landed cost/suggestion for every line now that the
// real trip logistics_cost is known, always updates products' cost_price,
// and only touches the live selling price when it hasn't been manually
// overridden — an overridden price is left untouched and the would-be
// suggestion is logged as a delta for review instead of applied.
class ReconcileProcurementAction
{
    public function __construct(private readonly PricingService $pricingService) {}

    public function execute(Procurement $procurement): void
    {
        if (! $procurement->isAwaitingLogistics()) {
            throw new RuntimeException('Only procurements awaiting logistics can be reconciled.');
        }

        if ($procurement->logistics_cost === null) {
            throw new RuntimeException('Enter the trip logistics cost before reconciling.');
        }

        DB::transaction(function () use ($procurement) {
            $userId = auth()->id();
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

                $newPrice = (float) $product->price;

                activity()
                    ->causedBy(auth()->user())
                    ->performedOn($product)
                    ->withProperties([
                        'procurement_id' => $procurement->id,
                        'trigger' => 'reconcile',
                        'old_cost_price' => $oldCostPrice,
                        'new_cost_price' => $computed['landed_unit_cost'],
                        'old_price' => $oldPrice,
                        'new_price' => $newPrice,
                        'suggested_price' => $computed['suggested_price'],
                        'price_overridden' => $product->price_overridden,
                    ])
                    ->tap(fn ($a) => $a->vendor_id = $procurement->vendor_id)
                    ->log($product->price_overridden
                        ? 'Reconciled procurement pricing — selling price kept (manually overridden), margin delta recorded'
                        : 'Reconciled procurement pricing — cost and selling price updated');
            }

            $procurement->update([
                'status' => 'reconciled',
                'reconciled_at' => now(),
                'logistics_recorded_by' => $userId,
            ]);
        });
    }
}
