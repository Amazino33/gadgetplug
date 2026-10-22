<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\DispatchMessages;
use App\Services\Orders\OrderLocation;
use App\Services\Orders\OrderStatusService;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The rider's page: one order, reached only by the signed link GadgetPlug
 * sends the logistics company (and the rider). No login — the signature is the
 * authority, it expires after 48 hours, and reassigning the order (or a failed
 * delivery) moves the link version on so an old link stops working.
 *
 * It shows what the rider needs to collect and deliver, and takes the
 * customer's 4-digit code to mark the order delivered. The code itself is
 * never shown here.
 */
class DispatchPageController extends Controller
{
    public function show(Request $request, Order $order, int $v): View
    {
        return $this->page($order, $v);
    }

    public function deliver(Request $request, Order $order, int $v): View
    {
        if ($this->stale($order, $v)) {
            return $this->page($order, $v);
        }

        $request->validate(['code' => ['required', 'string', 'max:8']]);

        $outcome = app(OrderStatusService::class)->confirmDeliveryWithCode($order, (string) $request->input('code'));

        return $this->page($order->refresh(), $v, $outcome);
    }

    private function page(Order $order, int $v, ?array $outcome = null): View
    {
        $order->loadMissing('items.product', 'checkout.lga', 'logisticsCompany');

        return view('dispatch.show', [
            'order'    => $order,
            'stale'    => $this->stale($order, $v),
            'outcome'  => $outcome,
            'pickups'  => DispatchMessages::pickupStores($order),
            'collect'  => $order->amountToCollect(),
            // The signed link is the assigned rider's alone, so it may carry
            // the customer's exact pin; /track never does.
            'directions'       => app(OrderLocation::class)->directionsUrl($order),
            'destinationExact' => (app(OrderLocation::class)->customer($order)['precision'] ?? null) === OrderLocation::EXACT,
            'items'    => $order->items->map(fn ($item) => [
                'name'     => TemplateRenderer::riderName($item),
                'quantity' => (int) $item->quantity,
            ]),
        ]);
    }

    /** Replaced by a newer assignment, or no longer with a platform company. */
    private function stale(Order $order, int $v): bool
    {
        return $v !== (int) $order->dispatch_link_version || ! $order->logistics_company_id;
    }
}
