<?php

declare(strict_types=1);

namespace App\Services\VendorLink\Rounding;

/**
 * Round to the closest ₦100.
 *
 * The shop's habit: ₦14,040 shows as ₦14,000 and ₦14,060 as ₦14,100, so a
 * marked-up figure lands on a price somebody would actually write on a shelf
 * instead of trailing kobo.
 *
 * Unlike EndsIn990 this can round DOWN, by at most ₦49 — deliberate, because
 * "closest" is what was asked for, and on a marked-up price that is noise. A
 * half lands upward (₦14,050 becomes ₦14,100), so the tie never costs the
 * markup.
 */
class Nearest100 implements RoundingRule
{
    public static function key(): string
    {
        return 'nearest_100';
    }

    public function apply(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        // round() is half-away-from-zero and the amount is positive here, so
        // this is half-up without further arithmetic.
        $rounded = round($amount / 100) * 100;

        // Below ₦50 the nearest hundred is zero, and a price of zero is a free
        // product rather than a cheap one. ₦100 is the lowest real shelf price.
        return (float) max(100, $rounded);
    }
}
