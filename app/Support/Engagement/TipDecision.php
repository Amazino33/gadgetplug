<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;

/**
 * The one tip to show, and what its button does for this tenant: go to a URL,
 * or fire a browser event that opens something on the page (the assistant).
 */
final class TipDecision
{
    public function __construct(
        public readonly Tip $tip,
        public readonly ?string $buttonUrl,
        public readonly int $slot,
        public readonly ?string $buttonEvent = null,
    ) {}
}
