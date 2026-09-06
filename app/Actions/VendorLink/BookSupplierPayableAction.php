<?php

declare(strict_types=1);

namespace App\Actions\VendorLink;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierLink;
use App\Services\VendorLink\SupplierCatalogue;
use App\Services\VendorLink\SupplierPayable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When goods reach the customer, book what the supplier is owed for them.
 *
 * At delivery, not at order. A pay-on-delivery order that never arrives — the
 * race where the supplier sells the last unit from his own counter first — is
 * cancelled with nothing paid by anybody, so it must leave no debt and no cost
 * behind it. Booking at checkout would create both for goods that never moved.
 *
 * The supplier's price is frozen onto the ledger row here and never recomputed.
 * He will change it again; what he charged for these units on the day they were
 * delivered is what is owed.
 *
 * The same figure is written to order_items.unit_cost, which is where this
 * codebase already keeps cost of goods sold — so profit reporting picks it up
 * with no knowledge of VendorLink at all, rather than through a parallel P&L.
 */
class BookSupplierPayableAction
{
    public function __construct(private readonly SupplierPayable $payable)
    {
    }

    /**
     * @return int how many lines were booked
     */
    public function execute(Order $order): int
    {
        $order->loadMissing('items.product');
        $booked = 0;

        foreach ($order->items as $item) {
            if (! $item->product?->isLinked()) {
                continue;
            }

            try {
                if ($this->bookLine($item)) {
                    $booked++;
                }
            } catch (Throwable $e) {
                // Never allowed to break a delivery. The goods are with the
                // customer either way, and a bookkeeping gap is recoverable
                // where refusing the status change is not — the same reasoning
                // RecognizePosSaleRevenueAction applies to revenue.
                Log::error("Supplier payable failed for order item {$item->id}: ".$e->getMessage());
            }
        }

        return $booked;
    }

    private function bookLine(OrderItem $item): bool
    {
        $link = SupplierLink::find($item->product->supplier_link_id);

        if (! $link) {
            return false;
        }

        // Read past tenancy: the source belongs to the supplier, and this may
        // run from a panel request where the scope would hide it.
        $source = SupplierCatalogue::find($link, (int) $item->product->source_product_id);

        // His price now is what he is owed. Falling back to the listing's
        // cost_price — the last resolved figure — rather than booking nothing,
        // because the goods have gone and a debt of zero would be a lie.
        $unitCost = $source
            ? (float) $source->price
            : (float) ($item->product->cost_price ?? 0);

        if ($unitCost <= 0) {
            return false;
        }

        $this->payable->charge($link, $item, (int) $item->quantity, $unitCost);

        // Cost of goods sold, on the line, in the column the rest of the
        // platform already reads. Only ever filled once — a second delivery
        // event must not restate a cost that has already been booked.
        if ($item->unit_cost === null) {
            $item->update(['unit_cost' => $unitCost]);
        }

        return true;
    }
}
