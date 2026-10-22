<?php

namespace App\Support;

use App\Models\Order;
use App\Services\Orders\OrderStatusService;

/**
 * One wording and one colour for each order status and payment state, used by
 * every admin screen that shows them (dispatch board, order page, logistics,
 * the rider's link), so "Handed to rider" is the same amber everywhere.
 */
final class OrderBadges
{
    /** Filament colours by order status. */
    public const STATUS_COLORS = [
        'pending'               => 'gray',
        'confirmed'             => 'info',
        'paid'                  => 'info',
        'paid_but_failed_stock' => 'danger',
        'packed'                => 'primary',
        'shipped'               => 'warning',
        'delivered'             => 'success',
        'returned'              => 'gray',
        'cancelled'             => 'danger',
    ];

    public const PAYMENT_METHOD_LABELS = [
        'paystack'        => 'Paid online',
        'pay_on_delivery' => 'Pay on delivery',
        'wallet'          => 'Reseller wallet',
    ];

    public static function statusLabel(?string $status): string
    {
        return OrderStatusService::LABELS[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    public static function statusColor(?string $status): string
    {
        return self::STATUS_COLORS[$status] ?? 'gray';
    }

    public static function paymentMethodLabel(?string $method): string
    {
        return self::PAYMENT_METHOD_LABELS[$method] ?? 'Paid online';
    }

    /** Where the money stands: paid already, or still to collect at the door. */
    public static function paymentState(Order $order): string
    {
        return match (true) {
            $order->payment_method === 'wallet'   => 'Reseller wallet',
            $order->payment_method === 'paystack' => 'Paid online',
            $order->isRevenueRecognized()         => 'POD — collected',
            default                               => 'POD — to collect',
        };
    }

    public static function paymentStateColor(string $state): string
    {
        return $state === 'POD — to collect' ? 'warning' : 'success';
    }
}
