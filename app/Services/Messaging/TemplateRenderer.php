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
     * The whole checkout, for the one customer confirmation: every part's
     * items (grouped by part when there is more than one) and the checkout's
     * own totals — items, delivery fee, what is paid or to pay.
     *
     * @return array<string, string>
     */
    public static function contextForCheckout(\App\Models\Checkout $checkout): array
    {
        $checkout->loadMissing('orders.items.product');
        $parts = $checkout->orders->sortBy('id')->values();
        $first = $parts->first();
        $prepaid = in_array($checkout->payment_method, ['paystack', 'wallet'], true);
        $fee = (float) $checkout->delivery_fee;

        $items = $parts->count() > 1
            ? $parts->map(fn (Order $part) => '*'.$part->reference."*\n".self::itemLines($part))->implode("\n\n")
            : ($first ? self::itemLines($first) : '');

        $summary = '*Items:* '.self::naira($checkout->items_total)
            ."\n*Delivery:* ".($fee > 0 ? self::naira($fee) : 'Free')
            ."\n*".($prepaid ? 'Total paid' : 'Total to pay').':* '.self::naira($checkout->grand_total)
            .($parts->count() > 1 ? "\n_Your order comes from ".$parts->count().' stores, so it may arrive in '.$parts->count().' deliveries._' : '');

        return [
            'customer_name'    => $checkout->customer_name,
            'customer_phone'   => $checkout->customer_phone,
            'order_number'     => $checkout->reference,
            'rider_name'       => '',
            'rider_phone'      => '',
            'company_name'     => '',
            'rider_line'       => 'Our dispatch rider will call you soon to arrange delivery.',
            'status'           => (string) $first?->status,
            'total'            => self::naira($checkout->grand_total),
            'delivery_address' => $checkout->shipping_address,
            'order_items'      => $items,
            'item_count'       => (string) $parts->sum(fn (Order $part) => self::itemCount($part)),
            'payment_method'   => $first ? self::paymentMethod($first) : (string) $checkout->payment_method,
            'payment_summary'  => $summary,
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
    // customer gets the online name they bought under, the storekeeper the
    // shelf name they pick by. The rider gets both — the shelf name to collect
    // by, then the online name in brackets when it differs, because they hand
    // over to a customer who knows the item by that name.
    private static function itemLines(Order $order, string $audience = self::AUDIENCE_CUSTOMER): string
    {
        $order->loadMissing('items.product');

        return $order->items
            ->map(function ($item) use ($audience) {
                // Snapshots first; a deleted product still names something.
                $name = match ($audience) {
                    self::AUDIENCE_CUSTOMER => $item->customerName(),
                    self::AUDIENCE_RIDER    => self::riderName($item),
                    default                 => $item->internalName(),
                };
                $line = (float) $item->quantity * (float) $item->unit_price;

                return '• ' . $item->quantity . ' x ' . $name . ' — ' . self::naira($line);
            })
            ->implode("\n");
    }

    /** "ITEL 2160 OLD ONE (itel 2160 Dual SIM Keypad Phone)" — or just the one name when they match. */
    public static function riderName(\App\Models\OrderItem $item): string
    {
        $internal = $item->internalName();
        $online = $item->customerName();

        return mb_strtolower(trim($internal)) === mb_strtolower(trim($online))
            ? $internal
            : "{$internal} ({$online})";
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
        // GadgetPlug's dispatch names the rider on the order itself; a
        // vendor's own rider record is only for orders from before that.
        $name  = $order->rider_name ?: $order->deliveryPerson?->name;
        $phone = $order->rider_name ? $order->rider_phone : $order->deliveryPerson?->phone;

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
