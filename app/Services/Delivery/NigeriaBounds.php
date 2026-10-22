<?php

namespace App\Services\Delivery;

/**
 * A box around Nigeria, with a little margin: a store or customer pin outside
 * it is a typo (swapped latitude/longitude, a dropped minus sign), not a place
 * we deliver from or to.
 */
final class NigeriaBounds
{
    public const MIN_LAT = 4.0;
    public const MAX_LAT = 14.0;
    public const MIN_LNG = 2.5;
    public const MAX_LNG = 15.0;

    public static function contains(float $lat, float $lng): bool
    {
        return $lat >= self::MIN_LAT && $lat <= self::MAX_LAT && $lng >= self::MIN_LNG && $lng <= self::MAX_LNG;
    }

    /** @return array<int, string> */
    public static function latitudeRules(): array
    {
        return ['numeric', 'between:'.self::MIN_LAT.','.self::MAX_LAT];
    }

    /** @return array<int, string> */
    public static function longitudeRules(): array
    {
        return ['numeric', 'between:'.self::MIN_LNG.','.self::MAX_LNG];
    }
}
