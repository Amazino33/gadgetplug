<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Support\Engagement\TipContext;
use Carbon\CarbonInterface;

/**
 * Something a user just did that makes a particular tip worth showing now.
 *
 * When a moment fires it is stored for that person for a short while (see
 * PendingMoments). A tip tied to the moment can only show while it is pending,
 * and then takes the day's slot ahead of ordinary tips.
 */
abstract class Moment
{
    abstract public function key(): string;

    /** What admins see in the moment picker. */
    abstract public function label(): string;

    /** True when the tip sets a number, as in "after N cash sales today". */
    public function hasThreshold(): bool
    {
        return false;
    }

    /** How long a fired moment stays pending. */
    public function expiresAt(): CarbonInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * For moments that are a page visit rather than something saved: return a
     * value to fire the moment for this render, or null for nothing.
     */
    public function detectOnRender(TipContext $context): ?int
    {
        return null;
    }
}
