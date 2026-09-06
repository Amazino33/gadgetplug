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
        'ends_990' => EndsIn990::class,
        'none'     => NoRounding::class,
    ];

    public static function make(?string $key): RoundingRule
    {
        $class = self::RULES[$key] ?? EndsIn990::class;

        return new $class();
    }

    /** @return array<string, string> for a select on the admin form. */
    public static function options(): array
    {
        return [
            'ends_990' => 'Round up to end in 990',
            'none'     => 'No rounding — exact markup',
        ];
    }
}
