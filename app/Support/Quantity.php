<?php

namespace App\Support;

use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * Quantities of goods, which are whole for every shop except the few that sell
 * halves (½ crate, 1½ crates).
 *
 * Every quantity column is DECIMAL(12,3) so that those shops can hold 19.5,
 * but nothing else changes for anyone: of() gives back an int whenever the
 * value is whole, so a supermarket's 19 is still the int 19 everywhere it is
 * read, compared or sent to a till.
 *
 * Halves are exact in binary floating point (0.5 = 2⁻¹), so half-steps add and
 * subtract with no drift. Everything is still rounded to 3dp, because a float
 * that came out of a division or a database driver may not be.
 */
final class Quantity
{
    public const SCALE = 3;

    /** Anything closer than this to a whole number or a half is that number. */
    private const EPSILON = 0.0005;

    /** The quantity, rounded to 3dp: an int when whole, a float otherwise. */
    public static function of(mixed $value): int|float
    {
        $rounded = round((float) $value, self::SCALE);

        return self::isWhole($rounded) ? (int) round($rounded) : $rounded;
    }

    public static function isWhole(mixed $value): bool
    {
        $float = (float) $value;

        return abs($float - round($float)) < self::EPSILON;
    }

    /** Whole, or a whole and a half: 0.5, 1, 1.5, 2 … */
    public static function isHalfStep(mixed $value): bool
    {
        return self::isWhole((float) $value * 2);
    }

    /** True when the quantity has a half in it: 0.5, 1.5 — not 1, not 2. */
    public static function hasHalf(mixed $value): bool
    {
        return self::isHalfStep($value) && ! self::isWhole($value);
    }

    /** The whole units in a quantity: 1 for 1.5, 0 for 0.5. */
    public static function wholePart(mixed $value): int
    {
        return (int) floor(round((float) $value, self::SCALE) + self::EPSILON);
    }

    public static function add(mixed ...$values): int|float
    {
        return self::of(array_sum(array_map('floatval', $values)));
    }

    public static function sub(mixed $a, mixed $b): int|float
    {
        return self::of((float) $a - (float) $b);
    }

    public static function mul(mixed $a, mixed $b): int|float
    {
        return self::of((float) $a * (float) $b);
    }

    /**
     * Whole units only, for anywhere that cannot sell a half: the online store.
     * 19.5 on the shelf is 19 that can be ordered online.
     */
    public static function wholeUnits(mixed $value): int
    {
        return self::wholePart($value);
    }

    public static function allowsHalves(?Vendor $vendor): bool
    {
        return (bool) ($vendor?->allow_half_quantities);
    }

    /**
     * Offline sync's rule. A sale queued on a till is accepted with halves if
     * the shop sells halves now OR ever has: a till may have rung ½ crate
     * before the switch went off and only reached signal afterwards. A till's
     * clock is not trusted to say which came first, so it is not asked.
     */
    public static function acceptsHalvesOnSync(?Vendor $vendor): bool
    {
        return self::allowsHalves($vendor) || $vendor?->half_quantities_disabled_at !== null;
    }

    /**
     * Why this quantity is not acceptable, or null when it is.
     *
     * $halves is the vendor, or a bool already decided by the caller (offline
     * sync passes acceptsHalvesOnSync()).
     */
    public static function errorFor(Vendor|bool|null $halves, mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return 'Enter a quantity.';
        }

        $allowHalves = is_bool($halves) ? $halves : self::allowsHalves($halves);

        if ((float) $value <= self::EPSILON) {
            return 'The quantity must be more than zero.';
        }

        if ($allowHalves) {
            return self::isHalfStep($value) ? null : 'Quantities go in halves: ½, 1, 1½, 2 …';
        }

        return self::isWhole($value) ? null : 'The quantity must be a whole number.';
    }

    /**
     * @throws ValidationException
     */
    public static function validateFor(Vendor|bool|null $halves, mixed $value, string $attribute = 'quantity'): int|float
    {
        if ($error = self::errorFor($halves, $value)) {
            throw ValidationException::withMessages([$attribute => $error]);
        }

        return self::of($value);
    }

    /**
     * For people to read. A half-selling shop sees "½", "1½", "12"; everyone
     * else sees the plain number, exactly as before.
     *
     * $halves is the vendor, or a bool when the caller already knows.
     */
    public static function format(mixed $value, Vendor|bool|null $halves = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $quantity    = self::of($value);
        $allowHalves = is_bool($halves) ? $halves : self::allowsHalves($halves);

        if (is_int($quantity)) {
            return (string) $quantity;
        }

        if ($allowHalves && self::hasHalf($quantity)) {
            $sign  = $quantity < 0 ? '-' : '';
            $whole = self::wholePart(abs($quantity));

            return $sign.($whole > 0 ? $whole : '').'½';
        }

        return rtrim(rtrim(number_format($quantity, self::SCALE, '.', ''), '0'), '.');
    }

    /** SQL that is true where a quantity column holds a fraction. Same on MySQL and SQLite. */
    public static function fractionalSql(string $column): string
    {
        return "ROUND({$column}, 0) <> {$column}";
    }

    /**
     * SQL for stock on hand less reserved, e.g. availableSql('src.') for an
     * aliased table.
     *
     * CAST AS DECIMAL, never AS SIGNED: MySQL rounds a decimal cast to SIGNED,
     * so 19.5 became 20 and 0.4 became 0. The cast itself is kept rather than a
     * bare subtraction because reserved_stock was UNSIGNED until the decimal
     * migration, and an unsigned subtraction that goes negative is an error on
     * MySQL — this stays safe in the moment between deploy and migrate.
     */
    public static function availableSql(string $prefix = '', string $quantity = 'stock_quantity', string $reserved = 'reserved_stock'): string
    {
        return "(CAST({$prefix}{$quantity} AS DECIMAL(12,3)) - CAST({$prefix}{$reserved} AS DECIMAL(12,3)))";
    }
}
