<?php

declare(strict_types=1);

namespace App\Services\VendorLink\Rounding;

/**
 * Resolves the rule named on a supplier link.
 *
 * A small registry rather than a match() scattered through pricing code: adding
 * a rule is one entry here, and nothing else has to know the rule exists.
 */
class RoundingRules
{
    /** @var array<string, class-string<RoundingRule>> */
    private const RULES = [
        'nearest_100' => Nearest100::class,
        'ends_990'    => EndsIn990::class,
        'none'        => NoRounding::class,
    ];

    /** What a link falls back to when it names no rule, or names one that is gone. */
    public const DEFAULT = 'nearest_100';

    public static function make(?string $key): RoundingRule
    {
        $class = self::RULES[$key] ?? self::RULES[self::DEFAULT];

        return new $class();
    }

    /** @return array<string, string> for a select on the admin form. */
    public static function options(): array
    {
        return [
            'nearest_100' => 'Round to the closest ₦100',
            'ends_990'    => 'Round up to end in 990',
            'none'        => 'No rounding — exact markup',
        ];
    }
}
