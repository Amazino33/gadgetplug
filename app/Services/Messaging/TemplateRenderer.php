<?php

namespace App\Services\Messaging;

use App\Models\Order;

class TemplateRenderer
{
    /**
     * @param  array<string, string>  $context
     */
    public function render(string $body, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string) $value;
        }

        return strtr($body, $replacements);
    }

    /** Who a message is for — decides which name each line carries. */
    public const AUDIENCE_CUSTOMER    = 'customer';
    public const AUDIENCE_STOREKEEPER = 'storekeeper';
    public const AUDIENCE_RIDER       = 'rider';

    /**
     * @param  string  $audience  customer: the online name the buyer saw;
     *                            storekeeper/rider: the shelf name staff pick by.
     * @return array<string, string>
     */
    public static function contextForOrder(Order $order, string $audience = self::AUDIENCE_CUSTOMER): array
    {
        return [
            'customer_name'    => $order->customer_name,
            'customer_phone'   => $order->customer_phone,
            'order_number'     => $order->reference,
            'rider_name'       => $order->deliveryPerson?->name ?? '',
            'rider_phone'      => $order->deliveryPerson?->phone ?? '',
            'company_name'     => $order->logisticsCompany?->name ?? '',
            'rider_line'       => self::riderLine($order),
            'status'           => $order->status,
            'total'            => self::naira($order->total_amount),
            'delivery_address' => $order->shipping_address,
            'order_items'      => self::itemLines($order, $audience),
            'item_count'       => (string) self::itemCount($order),
            'payment_method'   => self::paymentMethod($order),
            'payment_summary'  => self::paymentSummary($order),
        ];
    }

    /**
     * The money lines of a customer message: what the items come to, the
     * delivery fee, and what is paid (or still to pay at the door).
     *
     * The delivery fee belongs to the checkout, not to any vendor's order. In a
     * checkout split across vendors, the first part (REF-1) carries the fee and
     * the whole checkout's total; every other part shows its own items and
     * points at REF-1 for the fee — so the fee is never counted twice.
     */
    public static function paymentSummary(Order $order): string
    {
        $checkout = $order->checkout;
        $prepaid  = in_array($order->payment_method, ['paystack', 'wallet'], true);
        $label    = $prepaid ? 'Total paid' : 'Total to pay';
        $items    = (float) $order->total_amount;

        // An order created outside the checkout flow has no fee to show.
        if (! $checkout) {
            return '*Items:* '.self::naira($items)."
*{$label}:* ".self::naira($items);
        }

        $parts = $checkout->orders()->orderBy('id')->get(['id', 'reference']);
        $fee   = (float) $checkout->delivery_fee;
        $feeText = $fee > 0 ? self::naira($fee) : 'Free';

        if ($parts->count() <= 1) {
            return '*Items:* '.self::naira($items)
                ."
*Delivery:* {$feeText}"
                ."
*{$label}:* ".self::naira((float) $checkout->grand_total);
        }

        $first = $parts->first();

        if ($first->id === $order->id) {
            return '*Items (this part):* '.self::naira($items)
                ."
*Delivery (whole order):* {$feeText}"
                ."
*{$label} (whole order, all ".$parts->count().' parts):* '.self::naira((float) $checkout->grand_total);
        }

        return '*Items (this part):* '.self::naira($items)
            ."
*{$label} for this part:* ".self::naira($items)
            ."
Delivery fee is on {$first->reference}.";
    }

    // Itemised list for the message body, one line per product:
    //   • 2 x iPhone 13 Pro — ₦900,000.00
    // Uses the unit_price stored on the order line rather than the product's
    // current price, so a later price change never rewrites what the customer
    // was quoted — and the line's name snapshot for the same reason: the
    // customer gets the online name they bought under, staff get the shelf
    // name they pick by. (Riders get the shelf name for now; showing both is
    // planned with the order-operations work.)
    private static function itemLines(Order $order, string $audience = self::AUDIENCE_CUSTOMER): string
    {
        $order->loadMissing('items.product');

        return $order->items
            ->map(function ($item) use ($audience) {
                // Snapshots first; a deleted product still names something.
                $name = $audience === self::AUDIENCE_CUSTOMER
                    ? $item->customerName()
                    : $item->internalName();
                $line = (float) $item->quantity * (float) $item->unit_price;

                return '• ' . $item->quantity . ' x ' . $name . ' — ' . self::naira($line);
            })
            ->implode("\n");
    }

    private static function itemCount(Order $order): int
    {
        $order->loadMissing('items');

        return (int) $order->items->sum('quantity');
    }

    // Pre-composed sentence rather than raw rider fields. render() only does flat
    // placeholder substitution, so a template inlining {{rider_name}} and
    // {{rider_phone}} reads "your rider  () will call you" on every order that
    // reaches the customer before a rider is assigned — which is most of them.
    private static function riderLine(Order $order): string
    {
        $name  = $order->deliveryPerson?->name;
        $phone = $order->deliveryPerson?->phone;

        if (blank($name)) {
            return 'Our dispatch rider will call you soon to arrange delivery.';
        }

        $line = blank($phone)
            ? "Your dispatch rider {$name} will call you soon"
            : "Your dispatch rider {$name} ({$phone}) will call you soon";

        $company = $order->logisticsCompany?->name;

        return $line . (blank($company) ? '.' : " — delivered by {$company}.");
    }

    private static function paymentMethod(Order $order): string
    {
        return match ($order->payment_method) {
            'pay_on_delivery' => 'Pay on delivery',
            'paystack'        => 'Paid online',
            default           => (string) $order->payment_method,
        };
    }

    private static function naira(mixed $amount): string
    {
        return '₦' . number_format((float) $amount, 2);
    }
}
