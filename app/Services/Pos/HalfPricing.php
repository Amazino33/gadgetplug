<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Support\Quantity;

/**
 * What a line costs when it has a half in it.
 *
 * Only shops that sell halves ever reach the half branch; for every whole
 * quantity lineTotal() is exactly unit price × quantity, as it always was.
 *
 * The rules, agreed with the shops that sell halves:
 *
 *   - Retail: each unit may carry its own half price. Blank means half the
 *     unit's retail price, rounded to kobo.
 *   - Wholesale: always half the unit's wholesale price. A unit with no
 *     wholesale price falls back to the retail rule.
 *   - A line of q is floor(q) whole units plus, when q has a half, one half:
 *     Crate ₦10,100 with half ₦5,200 → 1½ = ₦15,300.
 *   - The price floor for a half is half the unit's floor.
 *
 * The till mirrors this in resources/js/pos/lib/halves.js; the server's figure
 * is the one that is kept.
 */
final class HalfPricing
{
    /**
     * The list price of one unit at this price type — what the catalogue says,
     * before any haggling. The base unit reads the product, which it mirrors.
     */
    public static function unitListPrice(Product $product, ?ProductUnit $unit, string $priceType): float
    {
        $wholesale = $priceType === 'wholesale';

        if ($unit === null || $unit->is_base) {
            return (float) ($wholesale && $product->wholesale_price !== null ? $product->wholesale_price : $product->price);
        }

        return (float) ($wholesale && $unit->wholesale_price !== null ? $unit->wholesale_price : $unit->price);
    }

    /** The catalogue price of half a unit, per the rules above. */
    public static function listHalfPrice(Product $product, ?ProductUnit $unit, string $priceType): float
    {
        $isBase = $unit === null || $unit->is_base;

        if ($priceType === 'wholesale') {
            $wholesale = $isBase ? $product->wholesale_price : $unit->wholesale_price;

            if ($wholesale !== null) {
                return round((float) $wholesale / 2, 2);
            }
        }

        $retail = $isBase ? (float) $product->price : (float) $unit->price;
        $set    = $isBase ? $product->half_price : $unit->half_price;

        return $set !== null ? round((float) $set, 2) : round($retail / 2, 2);
    }

    /**
     * The half price for a line the cashier may have haggled.
     *
     * At the list price the catalogue's half price applies — including a set
     * one, like ₦5,200 against a ₦10,100 crate. A haggled unit price has no
     * half of its own in the catalogue, so its half is exactly half of what was
     * agreed: the set half price was for the list price, not for this one.
     */
    public static function halfPriceFor(Product $product, ?ProductUnit $unit, string $priceType, float $soldUnitPrice): float
    {
        $list = self::unitListPrice($product, $unit, $priceType);

        if (abs($soldUnitPrice - $list) < 0.005) {
            return self::listHalfPrice($product, $unit, $priceType);
        }

        return round($soldUnitPrice / 2, 2);
    }

    /**
     * floor(q) × unit price, plus the half price when q has a half.
     *
     * For a whole q this is unit price × q exactly — the figure every line
     * has always had.
     */
    public static function lineTotal(float $unitPrice, ?float $halfPrice, int|float $quantity): float
    {
        $whole = Quantity::wholePart($quantity);
        $total = $unitPrice * $whole;

        if (Quantity::hasHalf($quantity)) {
            $total += $halfPrice ?? round($unitPrice / 2, 2);
        }

        return round($total, 2);
    }

    /** The lowest a line of q may come to: whole units at the floor, a half at half of it. */
    public static function floorTotal(float $unitFloor, int|float $quantity): float
    {
        $total = $unitFloor * Quantity::wholePart($quantity);

        if (Quantity::hasHalf($quantity)) {
            $total += $unitFloor / 2;
        }

        return round($total, 2);
    }
}
