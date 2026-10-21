<?php

namespace App\Actions\Checkout;

use App\Models\Checkout;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

// Writes one checkout and one child Order per vendor in the basket, each child
// carrying only that vendor's lines and subtotal.
//
// Shared by the storefront checkout and the reseller wallet purchase, so both
// ways an order comes into existence produce the same shape. Stock reservation
// and payment stay with the caller — they differ per path (pay-on-delivery
// reserves now, Paystack on confirmation, wallet immediately) — and are done
// per child order against these lines.
//
// Atomic: a failure (including the unique idempotency key refusing a duplicate
// submission) leaves no checkout, no order and no line behind.
class PlaceCheckoutAction
{
    /**
     * @param  array<string, mixed>  $attributes  checkout columns: reference, customer_*,
     *                                            shipping_address, payment_method, …
     * @param  array<int, array{product: Product, quantity: int, unit_price: float|int|string, unit_cost: float|int|string|null}>  $lines
     * @param  string  $orderStatus  the status each child order is created with
     */
    public function execute(array $attributes, array $lines, string $orderStatus = 'pending'): Checkout
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A checkout needs at least one line.');
        }

        // Grouped in the order vendors first appear in the basket, so child
        // references follow what the customer saw.
        $byVendor = [];
        foreach ($lines as $line) {
            $byVendor[$line['product']->vendor_id][] = $line;
        }

        return DB::transaction(function () use ($attributes, $byVendor, $orderStatus): Checkout {
            $itemsTotal  = 0.0;
            $subtotals   = [];

            foreach ($byVendor as $vendorId => $vendorLines) {
                $subtotals[$vendorId] = round(array_sum(array_map(
                    fn (array $line) => (float) $line['unit_price'] * (int) $line['quantity'],
                    $vendorLines,
                )), 2);
                $itemsTotal += $subtotals[$vendorId];
            }

            $itemsTotal  = round($itemsTotal, 2);
            $deliveryFee = round((float) ($attributes['delivery_fee'] ?? 0), 2);

            $checkout = Checkout::create(array_merge($attributes, [
                'items_total'  => $itemsTotal,
                'delivery_fee' => $deliveryFee,
                'grand_total'  => round($itemsTotal + $deliveryFee, 2),
            ]));

            $single = count($byVendor) === 1;
            $n      = 0;

            foreach ($byVendor as $vendorId => $vendorLines) {
                $n++;

                $order = Order::create([
                    'checkout_id'      => $checkout->id,
                    'user_id'          => $checkout->user_id,
                    // A single-vendor checkout's order shares the checkout's
                    // reference, so nothing a customer is told changes for the
                    // ordinary basket. A mixed basket's orders are -1, -2, …
                    'reference'        => $single ? $checkout->reference : $checkout->reference.'-'.$n,
                    'customer_name'    => $checkout->customer_name,
                    'customer_email'   => $checkout->customer_email,
                    'customer_phone'   => $checkout->customer_phone,
                    'shipping_address' => $checkout->shipping_address,
                    'local_government' => $checkout->local_government,
                    'total_amount'     => $subtotals[$vendorId],
                    'status'           => $orderStatus,
                    'payment_method'   => $checkout->payment_method,
                    'fbp'              => $checkout->fbp,
                    'fbc'              => $checkout->fbc,
                ]);

                foreach ($vendorLines as $line) {
                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $line['product']->id,
                        'vendor_id'  => $vendorId,
                        'quantity'   => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'unit_cost'  => $line['unit_cost'],
                    ]);
                }
            }

            return $checkout->load('orders.items.product');
        });
    }
}
