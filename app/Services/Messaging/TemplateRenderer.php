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
            'status'           => $order->status,
            'total'            => '₦' . number_format((float) $order->total_amount, 2),
            'delivery_address' => $order->shipping_address,
        ];
    }
}
