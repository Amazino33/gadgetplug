<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use App\Models\Tip;

/** The one tip to show, and where its button goes for this tenant. */
final class TipDecision
{
    public function __construct(
        public readonly Tip $tip,
        public readonly ?string $buttonUrl,
        public readonly int $slot,
    ) {}
}
