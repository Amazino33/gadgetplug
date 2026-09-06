<?php

declare(strict_types=1);

namespace App\Services\VendorLink\Rounding;

/** The markup exactly as calculated, to the kobo. */
class NoRounding implements RoundingRule
{
    public static function key(): string
    {
        return 'none';
    }

    public function apply(float $amount): float
    {
        return round(max(0.0, $amount), 2);
    }
}
