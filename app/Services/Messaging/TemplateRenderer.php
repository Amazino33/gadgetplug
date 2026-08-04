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

    /**
     * @return array<string, string>
     */
    public static function contextForOrder(Order $order): array
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
            'order_items'      => self::itemLines($order),
            'item_count'       => (string) self::itemCount($order),
            'payment_method'   => self::paymentMethod($order),
        ];
    }

    // Itemised list for the message body, one line per product:
    //   • 2 x iPhone 13 Pro — ₦900,000.00
    // Uses the unit_price stored on the order line rather than the product's
    // current price, so a later price change never rewrites what the customer
    // was quoted.
    private static function itemLines(Order $order): string
    {
        $order->loadMissing('items.product');

        return $order->items
            ->map(function ($item) {
                // Products can be deleted after an order is placed; the message
                // still has to name something the customer recognises.
                $name = $item->product?->name ?? 'Item';
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
