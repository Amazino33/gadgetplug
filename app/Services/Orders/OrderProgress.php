<?php

namespace App\Services\Orders;

use App\Models\Order;
use Carbon\CarbonInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * The order page's progress line: Placed → Confirmed/Paid → Packed → With
 * rider → Delivered, with Returned or Cancelled as a branch when they happen.
 *
 * Each step reached says when and by whom. Packing, handover, delivery,
 * return and cancellation have their own columns; confirming and paying do
 * not, so those come from the activity log of the status change.
 */
class OrderProgress
{
    public const DONE = 'done';
    public const CURRENT = 'current';
    public const UPCOMING = 'upcoming';

    /**
     * @return array<int, array{key: string, label: string, state: string, at: ?CarbonInterface, by: ?string}>
     */
    public function steps(Order $order): array
    {
        $reached = [
            'placed'    => ['at' => $order->created_at, 'by' => $order->customer_name ?: 'Customer'],
            'confirmed' => $this->confirmation($order),
            'packed'    => $order->packed_at ? ['at' => $order->packed_at, 'by' => $order->packedBy?->name] : null,
            'shipped'   => $order->handed_over_at ? ['at' => $order->handed_over_at, 'by' => $order->handedOverBy?->name] : null,
            'delivered' => $order->delivered_at ? ['at' => $order->delivered_at, 'by' => $this->deliveredBy($order)] : null,
        ];

        $labels = [
            'placed'    => 'Placed',
            'confirmed' => $order->payment_method === 'pay_on_delivery' ? 'Confirmed' : 'Paid',
            'packed'    => 'Packed',
            'shipped'   => 'With rider',
            'delivered' => 'Delivered',
        ];

        // "You are here": the furthest step reached, unless the order has left
        // the line (returned or cancelled), when nothing on it is current.
        $last = collect($reached)->filter()->keys()->last();
        $onBranch = $this->branch($order) !== null;

        return collect($labels)->map(fn (string $label, string $key) => [
            'key'   => $key,
            'label' => $label,
            'state' => match (true) {
                $reached[$key] === null             => self::UPCOMING,
                $key === $last && ! $onBranch       => self::CURRENT,
                default                             => self::DONE,
            },
            'at'    => $reached[$key]['at'] ?? null,
            'by'    => $reached[$key]['by'] ?? null,
        ])->values()->all();
    }

    /**
     * Returned to the store or cancelled, when either happened.
     *
     * @return array{key: string, label: string, at: ?CarbonInterface, by: ?string, reason: ?string}|null
     */
    public function branch(Order $order): ?array
    {
        if ($order->status === 'cancelled' || $order->cancelled_at) {
            return [
                'key'    => 'cancelled',
                'label'  => 'Cancelled',
                'at'     => $order->cancelled_at,
                'by'     => $order->cancelledBy?->name,
                'reason' => $order->cancel_reason,
            ];
        }

        if ($order->status === 'returned' || $order->returned_at) {
            return [
                'key'    => 'returned',
                'label'  => 'Returned to store',
                'at'     => $order->returned_at,
                'by'     => $order->returnedBy?->name,
                'reason' => $order->return_reason,
            ];
        }

        return null;
    }

    /** @return array{at: ?CarbonInterface, by: ?string}|null */
    private function confirmation(Order $order): ?array
    {
        $activity = Activity::query()
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->orderBy('id')
            ->get()
            ->first(fn (Activity $a) => in_array($a->properties['attributes']['status'] ?? null, ['confirmed', 'paid'], true));

        if ($activity) {
            return [
                'at' => $activity->created_at,
                'by' => $activity->causer?->name ?? ($order->payment_method === 'paystack' ? 'Paystack' : 'Checkout'),
            ];
        }

        // Past confirmation without a log entry (older orders): reached, time unknown.
        $beyond = ! in_array($order->status, ['pending', 'cancelled'], true) || $order->packed_at;

        return $beyond ? ['at' => null, 'by' => null] : null;
    }

    private function deliveredBy(Order $order): ?string
    {
        if ($order->delivered_via === 'code') {
            return 'Rider, with the customer\'s code';
        }

        return $order->deliveredBy?->name;
    }
}
