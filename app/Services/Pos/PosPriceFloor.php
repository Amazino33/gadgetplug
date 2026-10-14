<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Vendor;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

// The single place that decides how low a price may go at the till.
//
// Both the online sale endpoint and the offline-sync endpoint run every sale
// through guard(), so a negotiated price can't slip in by taking the other
// route or by a client posting whatever it likes.
class PosPriceFloor
{
    // Prices are money rounded to kobo; this only absorbs float representation
    // noise so a price sitting exactly on its floor isn't rejected.
    private const EPSILON = 0.005;

    /**
     * The lowest a single unit may sell for.
     *
     * Never returns more than the product's own list price: a vendor who
     * deliberately prices at or below cost must still be able to ring the sale
     * up normally. The floor constrains haggling below list — it isn't a
     * second opinion on what the vendor chose to charge.
     */
    public function floorFor(Product $product, float $minMarginPercent): float
    {
        $list = (float) $product->price;

        // No cost recorded means there's no way to know what losing money looks
        // like, so the price simply isn't negotiable until a cost exists.
        if (! $product->allow_pos_price_override || $product->cost_price === null) {
            return round($list, 2);
        }

        $costFloor = (float) $product->cost_price * (1 + $minMarginPercent / 100);

        return round(min($costFloor, $list), 2);
    }

    /**
     * The lowest one unit may sell for — one crate, at retail or wholesale.
     *
     * The same rule as floorFor(), measured against the unit's own list price
     * for the chosen price type and the cost of the base units inside it. The
     * base unit at retail is exactly floorFor(), so a product with no extra
     * units is held to the floor it always was.
     */
    public function floorForUnit(Product $product, ProductUnit $unit, string $priceType, float $minMarginPercent): float
    {
        $factor = max(1, (int) $unit->conversion_factor);

        if ($unit->is_base && $priceType !== 'wholesale') {
            return $this->floorFor($product, $minMarginPercent);
        }

        $list = $unit->is_base && $priceType === 'wholesale'
            ? (float) ($product->wholesale_price ?? $product->price)
            : ($unit->priceFor($priceType) ?? $unit->priceFor('retail'));

        if (! $product->allow_pos_price_override || $product->cost_price === null) {
            return round($list, 2);
        }

        $costFloor = (float) $product->cost_price * $factor * (1 + $minMarginPercent / 100);

        return round(min($costFloor, $list), 2);
    }

    /**
     * @param  array<int, array{product_id: int, unit_price: float|string, quantity: int, discount_amount?: float|string}>  $items
     * @param  float  $netAfterDiscounts  Goods value after every discount, before VAT.
     *
     * @throws ValidationException
     */
    public function guard(Vendor $vendor, array $items, float $netAfterDiscounts): void
    {
        $margin = (float) ($vendor->pos_min_margin_percent ?? 0);

        $products = Product::whereIn('id', collect($items)->pluck('product_id'))
            ->where('vendor_id', $vendor->id)
            ->get()
            ->keyBy('id');

        $floorTotal = 0.0;

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => 'This sale contains a product that does not belong to this store.',
                ]);
            }

            // A line sold in a unit (resolved by SaleLineResolver) is measured
            // per unit sold: one crate against the crate's floor.
            $unit = ! empty($item['product_unit_id'])
                ? ProductUnit::where('product_id', $product->id)->find($item['product_unit_id'])
                : null;

            if ($unit) {
                $floor    = $this->floorForUnit($product, $unit, $item['price_type'] ?? 'retail', $margin);
                $quantity = Quantity::of($item['unit_qty']);
                $lineNet  = (float) $item['line_gross'] - (float) ($item['discount_amount'] ?? 0);
            } else {
                $floor    = $this->floorFor($product, $margin);
                $quantity = Quantity::of($item['quantity']);
                // line_gross when the resolver supplied it: with a half in the
                // line, unit price × quantity is not what the customer pays.
                $lineNet  = (float) ($item['line_gross'] ?? (float) $item['unit_price'] * $quantity)
                    - (float) ($item['discount_amount'] ?? 0);
            }

            // Whole units at the unit's floor, and a half at half of it. For a
            // whole quantity this is floor × quantity, as it always was.
            $lineTotalFloor = HalfPricing::floorTotal($floor, $quantity);

            $floorTotal += $lineTotalFloor;

            if ($lineNet + self::EPSILON < $lineTotalFloor) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        '%s cannot go below %s each.',
                        $product->name,
                        $this->money($floor),
                    ),
                ]);
            }
        }

        // Each line above was measured on its own, before the cart-level
        // discount is taken off the basket. Without this second check every
        // line can sit exactly on its floor, pass, and still be dragged under
        // it by the cart discount — the sale ends up below cost with nothing
        // having failed on the way through.
        if ($netAfterDiscounts + self::EPSILON < $floorTotal) {
            throw ValidationException::withMessages([
                'discount_amount' => sprintf(
                    'That discount takes this sale below %s, the lowest these items can go.',
                    $this->money($floorTotal),
                ),
            ]);
        }
    }

    private function money(float $amount): string
    {
        return '₦'.number_format($amount, 2);
    }
}
