<?php

declare(strict_types=1);

namespace App\Actions\VendorLink;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupplierLink;
use App\Services\VendorLink\SupplierCatalogue;
use App\Services\VendorLink\SupplierPayable;
use App\Services\VendorLink\SupplierPriceAtSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Book what the supplier is owed for an order's resold lines.
 *
 * At the moment the order's revenue is booked — OrderStatusService calls this
 * right after recognising it: a Paystack order when it is paid, a
 * pay-on-delivery order when GadgetPlug records the money received. Revenue
 * and the cost of earning it land together, on either channel. A
 * pay-on-delivery order that never arrives — the race where the supplier sells
 * the last unit from his own counter first — is cancelled before any money is
 * received, so it leaves no debt and no cost behind it.
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
     * $soldAt is for booking after the fact (the one-off backfill): the line
     * is priced at what the supplier charged on that date
     * (SupplierPriceAtSale), not at his price today.
     *
     * @return int how many lines were booked
     */
    public function execute(Order $order, ?CarbonInterface $soldAt = null): int
    {
        $order->loadMissing('items.product');
        $booked = 0;

        foreach ($order->items as $item) {
            if (! $item->product?->isLinked()) {
                continue;
            }

            try {
                if ($this->bookLine($item, $soldAt)) {
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

    private function bookLine(OrderItem $item, ?CarbonInterface $soldAt = null): bool
    {
        $link = SupplierLink::find($item->product->supplier_link_id);

        if (! $link) {
            return false;
        }

        if ($soldAt !== null) {
            $unitCost = (float) (app(SupplierPriceAtSale::class)->resolve($item, $soldAt)['cost'] ?? 0);

            return $unitCost > 0 && $this->charge($link, $item, $unitCost);
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

        return $this->charge($link, $item, $unitCost);
    }

    private function charge(SupplierLink $link, OrderItem $item, float $unitCost): bool
    {
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
