<?php

declare(strict_types=1);

namespace App\Services\Reporting;

// One row of ProductVelocityService's output. Tier is the only field a
// caller should branch on for "what does this product need" — dailyVelocity/
// daysOfCover/etc. are the numbers behind that decision, not a second
// classification of their own.
readonly class RestockAnalysisResult
{
    public const TIER_WATCH = 'watch';
    public const TIER_URGENT = 'urgent';
    public const TIER_REORDER_NOW = 'reorder_now';
    public const TIER_HEALTHY = 'healthy';
    public const TIER_DEAD_STOCK_CANDIDATE = 'dead_stock_candidate';
    public const TIER_REVIEW = 'review';
    public const TIER_STARVED_REVIEW = 'starved_review';

    public const LABELS = [
        self::TIER_WATCH               => 'Watch',
        self::TIER_URGENT              => 'Urgent',
        self::TIER_REORDER_NOW         => 'Reorder now',
        self::TIER_HEALTHY             => 'Healthy',
        self::TIER_DEAD_STOCK_CANDIDATE => 'Dead stock candidate',
        self::TIER_REVIEW              => 'Review',
        self::TIER_STARVED_REVIEW      => 'Starved — review',
    ];

    public function __construct(
        public int $productId,
        public float $dailyVelocity,
        public int $currentStock,
        public ?float $daysOfCover,
        public int $reorderQuantity,
        public string $tier,
        public bool $isNew,
        public ?int $daysOutOfStock = null,
    ) {}

    // What the restock report's table actually filters/sorts on — the two
    // tiers where a reorder_quantity means something.
    public function needsRestock(): bool
    {
        return in_array($this->tier, [self::TIER_URGENT, self::TIER_REORDER_NOW], true);
    }

    public function isDeadStockCandidate(): bool
    {
        return $this->tier === self::TIER_DEAD_STOCK_CANDIDATE;
    }

    public const COLORS = [
        self::TIER_WATCH               => 'info',
        self::TIER_URGENT              => 'danger',
        self::TIER_REORDER_NOW         => 'warning',
        self::TIER_HEALTHY             => 'success',
        self::TIER_DEAD_STOCK_CANDIDATE => 'gray',
        self::TIER_REVIEW              => 'gray',
        self::TIER_STARVED_REVIEW      => 'warning',
    ];

    public function label(): string
    {
        return self::LABELS[$this->tier] ?? ucfirst($this->tier);
    }

    public function color(): string
    {
        return self::COLORS[$this->tier] ?? 'gray';
    }
}
