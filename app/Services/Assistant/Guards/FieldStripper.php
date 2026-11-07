<?php

namespace App\Services\Assistant\Guards;

/**
 * Removes cost, profit and margin from a tool result for anyone without
 * view_cost_price — before the model ever sees it.
 *
 * By key name, recursively, so a tool added next month cannot leak a figure
 * simply because its author forgot to hide it: any key that names cost,
 * profit or margin is dropped wherever it sits. Tools still decide for
 * themselves where they can do better (not computing it at all); this is the
 * net under them.
 */
final class FieldStripper
{
    private const PATTERN = '/(^|_)(cost|costs|profit|profits|margin|margins|markup|cogs)(_|$)/i';

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function stripCost(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isCostKey($key)) {
                continue;
            }

            $out[$key] = is_array($value) ? self::stripCost($value) : $value;
        }

        return $out;
    }

    public static function isCostKey(string $key): bool
    {
        return (bool) preg_match(self::PATTERN, $key);
    }
}
