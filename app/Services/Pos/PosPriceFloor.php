<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\Vendor;
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

            $floor    = $this->floorFor($product, $margin);
            $quantity = (int) $item['quantity'];
            $lineNet  = ((float) $item['unit_price'] * $quantity) - (float) ($item['discount_amount'] ?? 0);
            $lineTotalFloor = $floor * $quantity;

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
