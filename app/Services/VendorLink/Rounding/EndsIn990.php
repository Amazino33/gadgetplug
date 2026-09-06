<?php

declare(strict_types=1);

namespace App\Services\VendorLink\Rounding;

/**
 * Round UP to the next price ending in 990.
 *
 * Always up, never down: rounding down would quietly sell below the markup the
 * link was set to earn. N14,000 becomes N14,990; N14,980 becomes N14,990; and a
 * price already ending in 990 is left exactly where it is rather than pushed up
 * another thousand.
 */
class EndsIn990 implements RoundingRule
{
    public static function key(): string
    {
        return 'ends_990';
    }

    public function apply(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        // Work in whole naira: kobo have no place in a shelf price, and a
        // fractional amount would otherwise never land exactly on 990.
        $whole = (int) ceil($amount);
        $thousands = intdiv($whole, 1000) * 1000;
        $candidate = $thousands + 990;

        // Below 990 there is no lower band to fall back to, so the first
        // sensible shelf price is 990 itself.
        return (float) ($candidate >= $whole ? $candidate : $candidate + 1000);
    }
}
