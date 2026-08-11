<?php

namespace App\Support\Accountability;

use App\Models\Product;

// The loss, priced at the moment it was established, and never recalculated.
//
// A shortage is charged at retail because that is what the store actually lost
// by not having the item to sell: the cost of replacing it plus the margin it
// would have earned. Splitting the two here means the financial layer (Phase 4)
// can book each half to the right place without re-deriving anything from
// product settings that will have moved on by then.
//
// Immutable by construction — readonly, and the ledger copies these values into
// its own frozen columns.
final readonly class FrozenLossSnapshot
{
    private function __construct(
        public int $shortageQty,
        public float $unitCostSnapshot,
        public float $unitPriceSnapshot,
        public float $chargeAmount,
        public float $costComponent,
        public float $marginComponent,
        public bool $priceFallback,
    ) {}

    /**
     * @param  int  $shortageQty  Units missing, as a positive number.
     */
    public static function forProduct(Product $product, int $shortageQty): self
    {
        $quantity = abs($shortageQty);

        // A product with no recorded cost is treated as costing nothing rather
        // than blocking the line. The charge still stands; it simply has no cost
        // component to book against.
        $unitCost = $product->cost_price !== null ? (float) $product->cost_price : 0.0;

        $listedPrice = (float) $product->price;

        // No usable retail price — the SKUs that show a dash. Charge at cost so
        // the store recovers what it paid, flag it, and never block the line.
        $priceFallback = $listedPrice <= 0.0;
        $unitPrice     = $priceFallback ? $unitCost : $listedPrice;

        return self::fromValues($quantity, $unitCost, $unitPrice, $priceFallback);
    }

    /**
     * Rebuild a snapshot from values already frozen on a ledger row, so callers
     * can reason about a historic charge without touching the product again.
     */
    public static function fromFrozen(
        int $shortageQty,
        float $unitCostSnapshot,
        float $unitPriceSnapshot,
        bool $priceFallback,
    ): self {
        return self::fromValues($shortageQty, $unitCostSnapshot, $unitPriceSnapshot, $priceFallback);
    }

    private static function fromValues(int $quantity, float $unitCost, float $unitPrice, bool $priceFallback): self
    {
        $chargeAmount = round($quantity * $unitPrice, 2);
        $costComponent = round($quantity * $unitCost, 2);

        // Derived by subtraction rather than from a margin figure, so the three
        // always reconcile exactly: cost + margin == charge, to the kobo.
        $marginComponent = round($chargeAmount - $costComponent, 2);

        return new self(
            shortageQty: $quantity,
            unitCostSnapshot: round($unitCost, 2),
            unitPriceSnapshot: round($unitPrice, 2),
            chargeAmount: $chargeAmount,
            costComponent: $costComponent,
            marginComponent: $marginComponent,
            priceFallback: $priceFallback,
        );
    }

    /** @return array<string, mixed> Column values for a ledger row. */
    public function toLedgerColumns(): array
    {
        return [
            'shortage_qty'        => $this->shortageQty,
            'unit_cost_snapshot'  => $this->unitCostSnapshot,
            'unit_price_snapshot' => $this->unitPriceSnapshot,
            'charge_amount'       => $this->chargeAmount,
            'cost_component'      => $this->costComponent,
            'margin_component'    => $this->marginComponent,
            'price_fallback'      => $this->priceFallback,
        ];
    }
}
