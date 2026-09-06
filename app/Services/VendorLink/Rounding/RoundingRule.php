<?php

declare(strict_types=1);

namespace App\Services\VendorLink\Rounding;

/**
 * How a marked-up price is tidied into a price a shop would actually display.
 *
 * A contract rather than a helper because the habit is a commercial preference,
 * not a law: "ends in 990" is what this shop does today, and the next one may
 * round to the nearest 500. Naming the rule on the link keeps that decision out
 * of the pricing arithmetic entirely.
 */
interface RoundingRule
{
    /** The identifier stored on supplier_links.rounding_rule. */
    public static function key(): string;

    public function apply(float $amount): float;
}
