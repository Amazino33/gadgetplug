<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Store;
use App\Services\Delivery\DeliveryDistance;

/**
 * Where an order goes from and to, for the order page's map, the rider's link
 * and the dispatch board.
 *
 * From: the store holding the stock (the first allocated branch with a pin).
 * To: the customer's shared location when they gave one at checkout, otherwise
 * their LGA's reference point — labelled as approximate wherever it is shown.
 * Distance is straight-line, never road. Directions hand off to Google Maps'
 * public URL: no key, no paid API.
 *
 * The exact pin is staff- and rider-only: nothing here is used on /track.
 */
class OrderLocation
{
    public const EXACT = 'exact';
    public const APPROXIMATE = 'approximate';

    /** @return array{lat: float, lng: float, name: string}|null */
    public function store(Order $order): ?array
    {
        $store = DispatchMessages::pickupStores($order)
            ->first(fn (Store $s) => $s->latitude !== null && $s->longitude !== null);

        return $store ? [
            'lat'  => (float) $store->latitude,
            'lng'  => (float) $store->longitude,
            'name' => trim(($store->vendor?->name ? $store->vendor->name.' — ' : '').$store->name),
        ] : null;
    }

    /** @return array{lat: float, lng: float, precision: string, label: string}|null */
    public function customer(Order $order): ?array
    {
        $checkout = $order->checkout;

        if ($checkout?->customer_latitude !== null && $checkout?->customer_longitude !== null) {
            return [
                'lat'       => (float) $checkout->customer_latitude,
                'lng'       => (float) $checkout->customer_longitude,
                'precision' => self::EXACT,
                'label'     => 'Customer\'s shared location',
            ];
        }

        $lga = $checkout?->lga;

        if ($lga && $lga->latitude !== null && $lga->longitude !== null) {
            return [
                'lat'       => (float) $lga->latitude,
                'lng'       => (float) $lga->longitude,
                'precision' => self::APPROXIMATE,
                'label'     => 'Approximate (LGA centre) — '.$lga->name,
            ];
        }

        return null;
    }

    /** Straight-line km from the store to the customer point, or null. */
    public function km(Order $order): ?float
    {
        $from = $this->store($order);
        $to = $this->customer($order);

        return $from && $to ? round(DeliveryDistance::km($from['lat'], $from['lng'], $to['lat'], $to['lng']), 1) : null;
    }

    /** Google Maps directions from the store to the customer, or null without both points. */
    public function directionsUrl(Order $order): ?string
    {
        $from = $this->store($order);
        $to = $this->customer($order);

        return $from && $to ? self::directions($from['lat'], $from['lng'], $to['lat'], $to['lng']) : null;
    }

    public static function directions(float $fromLat, float $fromLng, float $toLat, float $toLng): string
    {
        return 'https://www.google.com/maps/dir/?api=1&origin='
            .self::coord($fromLat).','.self::coord($fromLng)
            .'&destination='.self::coord($toLat).','.self::coord($toLng);
    }

    /**
     * How far the shared pin is from the chosen LGA's point, when there is a
     * shared pin to compare; null otherwise.
     */
    public function mismatchKm(Order $order): ?float
    {
        $checkout = $order->checkout;
        $lga = $checkout?->lga;

        if ($checkout?->customer_latitude === null || $checkout?->customer_longitude === null || ! $lga || $lga->latitude === null) {
            return null;
        }

        return round(DeliveryDistance::km(
            (float) $checkout->customer_latitude,
            (float) $checkout->customer_longitude,
            (float) $lga->latitude,
            (float) $lga->longitude,
        ), 1);
    }

    /** "Location doesn't match LGA": the shared pin is further than config('delivery.location_mismatch_km') from it. */
    public function mismatched(Order $order): bool
    {
        $km = $this->mismatchKm($order);

        return $km !== null && $km > (float) config('delivery.location_mismatch_km', 15);
    }

    /** Everything the order page's map draws. */
    public function mapData(Order $order): array
    {
        return [
            'store'      => $this->store($order),
            'customer'   => $this->customer($order),
            'km'         => $this->km($order),
            'directions' => $this->directionsUrl($order),
        ];
    }

    private static function coord(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
