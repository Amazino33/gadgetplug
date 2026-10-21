<?php

namespace App\Services\Delivery;

use App\Models\Lga;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Straight-line ("as the crow flies") distance from our nearest delivery hub.
 *
 * A hub is any active store with coordinates. Distances are great-circle
 * (haversine) between one approximate point per LGA and the hub's pin, so the
 * road distance is always longer — every screen that shows one says so.
 * Computed here, on the server, so checkout, the settings page and the
 * price-by-distance helper all agree.
 */
class DeliveryDistance
{
    private const EARTH_RADIUS_KM = 6371.0;

    /** @var Collection<int, Store>|null */
    private ?Collection $hubs = null;

    /** @return Collection<int, Store> */
    public function hubs(): Collection
    {
        return $this->hubs ??= Store::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('id')
            ->get(['id', 'vendor_id', 'name', 'latitude', 'longitude']);
    }

    public function forgetHubs(): void
    {
        $this->hubs = null;
    }

    /**
     * The nearest hub to an LGA and how far it is, or null when no store has
     * been pinned yet.
     *
     * @return array{store: Store, km: float}|null
     */
    public function nearestHub(Lga $lga): ?array
    {
        $best = null;

        foreach ($this->hubs() as $hub) {
            $km = self::km((float) $hub->latitude, (float) $hub->longitude, (float) $lga->latitude, (float) $lga->longitude);

            if ($best === null || $km < $best['km']) {
                $best = ['store' => $hub, 'km' => $km];
            }
        }

        return $best;
    }

    /** Straight-line km from the nearest hub, rounded to 0.1, or null with no hubs. */
    public function kmFromNearestHub(Lga $lga): ?float
    {
        $nearest = $this->nearestHub($lga);

        return $nearest ? round($nearest['km'], 1) : null;
    }

    /** Haversine great-circle distance in kilometres. */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
