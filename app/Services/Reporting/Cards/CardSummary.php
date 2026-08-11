<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

// The uniform shape every reports-hub card renders from. A card that can only
// ever show "headline" with no comparison/count/urgency signal is exactly the
// "same reassuring number every day" pattern the hub is built to reject — see
// each provider's own reasoning for why its urgency isn't hardcoded to calm.
readonly class CardSummary
{
    public const URGENCY_CALM = 'calm';
    public const URGENCY_ATTENTION = 'attention';
    public const URGENCY_URGENT = 'urgent';

    public function __construct(
        public string $key,
        public string $title,
        public string $headline,
        public ?string $comparison = null,
        public ?string $comparisonDirection = null, // 'up' | 'down' | 'flat'
        public ?int $actionableCount = null,
        public string $urgency = self::URGENCY_CALM,
        public ?string $link = null,
        public ?string $note = null,
    ) {}

    public function color(): string
    {
        return match ($this->urgency) {
            self::URGENCY_URGENT => 'danger',
            self::URGENCY_ATTENTION => 'warning',
            default => 'success',
        };
    }

    public function hasLink(): bool
    {
        return $this->link !== null;
    }
}
