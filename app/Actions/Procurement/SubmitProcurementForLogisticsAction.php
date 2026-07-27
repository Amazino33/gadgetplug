<?php

namespace App\Actions\Procurement;

use App\Models\InventoryLedger;
use App\Models\Procurement;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Storekeeper's half of the two-person workflow: draft -> awaiting_logistics.
// Prices every line provisionally (logistics_cost is still null on the
// procurement at this point, so PricingService::priceTrip() resolves to
// factor 1 — landed cost = purchase price) and takes stock live immediately,
// mirroring ApproveProcurementAction's restock behavior for the old flow.
class SubmitProcurementForLogisticsAction
{
    public function __construct(private readonly PricingService $pricingService) {}

    public function execute(Procurement $procurement): void
    {
        if (! $procurement->isDraft()) {
            throw new RuntimeException('Only draft procurements can be submitted for logistics.');
        }

        if ($procurement->items()->count() === 0) {
            throw new RuntimeException('Add at least one line item before submitting.');
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
                $product->increment('stock_quantity', $item->quantity);

                $productUpdates = ['cost_price' => $computed['landed_unit_cost']];
                if (! $product->price_overridden) {
                    $productUpdates['price'] = $computed['suggested_price'];
                }
                $product->update($productUpdates);

                InventoryLedger::create([
                    'vendor_id' => $procurement->vendor_id,
                    'product_id' => $item->product_id,
                    'user_id' => $userId,
                    'transaction_type' => 'restock',
                    'quantity_change' => $item->quantity,
                    'reference' => $procurement->reference,
                    'description' => "Procurement submitted for logistics (provisional pricing): {$procurement->reference}",
                ]);
            }

            $procurement->update(['status' => 'awaiting_logistics']);
        });
    }
}
