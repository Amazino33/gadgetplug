<?php

declare(strict_types=1);

namespace App\Support\Engagement\Conditions;

use App\Support\Engagement\TipContext;

/**
 * A smart targeting rule an admin picks from a list. Always code, never
 * admin-typed SQL. "Has not used the linked feature" is not one of these: it
 * is the tip's hide_if_feature_used switch, on by default, and runs for every
 * tip with a feature whatever condition is picked here.
 */
interface Condition
{
    public function key(): string;

    public function label(): string;

    /** True when the admin must also enter a number (N). */
    public function needsValue(): bool;

    public function passes(TipContext $context, ?int $value): bool;
}
