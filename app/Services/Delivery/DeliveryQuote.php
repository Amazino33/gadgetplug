<?php

namespace App\Services\Delivery;

use App\Models\DeliveryRule;
use App\Models\Lga;

/** What delivering to one LGA costs, and why — the answer checkout charges and snapshots. */
final class DeliveryQuote
{
    public function __construct(
        public readonly Lga $lga,
        public readonly ?DeliveryRule $rule,
        public readonly string $mode,
        public readonly float $fee,
        public readonly ?float $expectedCost,
        public readonly ?string $deliveryDays,
        /** 'lga' (an override), 'state' (the state default) or 'none' (no rule at all). */
        public readonly string $source,
        public readonly ?float $distanceKm,
    ) {}

    public function deliverable(): bool
    {
        return $this->mode !== DeliveryRule::NOT_DELIVERED;
    }

    /**
     * Frozen onto the checkout, so a later price change never alters what a
     * past order was charged or why.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'mode'          => $this->mode,
            'fee'           => $this->fee,
            'expected_cost' => $this->expectedCost,
            'delivery_days' => $this->deliveryDays,
            'rule_id'       => $this->rule?->id,
            'source'        => $this->source,
            'lga'           => $this->lga->name,
            'state'         => $this->lga->state?->name,
            'distance_km'   => $this->distanceKm,
            'distance_kind' => 'straight_line',
        ];
    }
}
