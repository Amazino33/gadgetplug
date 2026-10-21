<?php

namespace App\Services\Delivery;

use App\Models\DeliveryRule;
use App\Models\Lga;

/**
 * Resolves the delivery rule that applies to an LGA: its own override if it has
 * one, otherwise its state's default, otherwise "not delivered". The single
 * answer checkout, the settings page and the price-by-distance helper share.
 */
class DeliveryPricing
{
    public function __construct(private readonly DeliveryDistance $distance) {}

    public function quote(Lga $lga): DeliveryQuote
    {
        $lga->loadMissing('state');

        $override = DeliveryRule::query()->where('lga_id', $lga->id)->first();
        $default  = $override ? null : DeliveryRule::query()->where('state_id', $lga->state_id)->whereNull('lga_id')->first();
        $rule     = $override ?? $default;

        return new DeliveryQuote(
            lga: $lga,
            rule: $rule,
            mode: $rule?->mode ?? DeliveryRule::NOT_DELIVERED,
            fee: $rule && $rule->mode === DeliveryRule::FEE ? (float) $rule->fee : 0.0,
            expectedCost: $rule?->expected_cost !== null ? (float) $rule->expected_cost : null,
            deliveryDays: $rule?->delivery_days,
            source: $override ? 'lga' : ($default ? 'state' : 'none'),
            distanceKm: $this->distance->kmFromNearestHub($lga),
        );
    }

    public function quoteForLgaId(?int $lgaId): ?DeliveryQuote
    {
        $lga = $lgaId ? Lga::query()->with('state')->find($lgaId) : null;

        return $lga ? $this->quote($lga) : null;
    }
}
