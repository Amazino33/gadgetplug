<?php

namespace App\Services;

use App\Models\Procurement;

// Pure pricing math for procurement auto-pricing. The calculation methods
// take primitive values and return primitive values — no DB access, no
// model writes — so they're trivial to unit test. priceTrip() is the only
// method that touches models, and it's a thin read-only orchestration layer:
// it returns computed values keyed by procurement_item id, it never persists.
// Callers (Batch 2's submit/reconcile actions) decide what to save and when.
class PricingService
{
    // L (logistics_cost) is null when not yet known (provisional pricing) —
    // that collapses to factor 1, i.e. landed cost = purchase price.
    // A zero trip value is treated the same way to avoid a division by zero.
    public function logisticsFactor(?float $logisticsCost, float $tripValue): float
    {
        if ($logisticsCost === null || $tripValue == 0.0) {
            return 1.0;
        }

        return 1 + ($logisticsCost / $tripValue);
    }

    public function landedUnitCost(float $purchasePrice, float $factor): float
    {
        return $purchasePrice * $factor;
    }

    // Order is non-negotiable: markup -> cap check on raw profit -> round last.
    // Rounding may nudge final profit a little past the cap; that's accepted
    // noise, the cap is never re-checked after rounding.
    public function suggestedPrice(float $landed, float $markup): float
    {
        $rawPrice = $landed * (1 + $markup);
        $rawProfit = $rawPrice - $landed;

        $profitCap = (float) config('pricing.profit_cap');
        $price = $rawProfit > $profitCap ? ($landed + $profitCap) : $rawPrice;

        return $this->roundToNearest($price, (float) config('pricing.rounding_step'));
    }

    public function roundToNearest(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }

        return round($value / $step) * $step;
    }

    /**
     * Compute landed_unit_cost + suggested_price for every line in a
     * procurement. Read-only — does not write to the database.
     *
     * @return array<int, array{landed_unit_cost: float, suggested_price: float}>
     */
    public function priceTrip(Procurement $procurement): array
    {
        $items = $procurement->items()->with('product.category')->get();

        $tripValue = (float) $items->sum(fn ($item) => (float) $item->unit_cost * $item->quantity);

        $factor = $this->logisticsFactor(
            $procurement->logistics_cost !== null ? (float) $procurement->logistics_cost : null,
            $tripValue,
        );

        return $items->mapWithKeys(function ($item) use ($factor) {
            $landed = $this->landedUnitCost((float) $item->unit_cost, $factor);

            $categoryMarkup = $item->product?->category?->markup;
            $markup = $categoryMarkup !== null ? (float) $categoryMarkup : (float) config('pricing.fallback_markup');

            return [
                $item->id => [
                    'landed_unit_cost' => $landed,
                    'suggested_price' => $this->suggestedPrice($landed, $markup),
                ],
            ];
        })->all();
    }
}
