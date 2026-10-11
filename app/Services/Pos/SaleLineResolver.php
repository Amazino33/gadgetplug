<?php

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns the lines a till sends into the lines a sale records.
 *
 * A line sold in a unit ("1 Crate at ₦9,700") arrives with product_unit_id and
 * unit_qty. The server — never the till — looks the unit up and works out the
 * base quantity (1 × 24 bottles), because that is what leaves the shelf and
 * what every report counts. The money is the unit price times the units sold,
 * so a crate costs what the crate is priced at, not 24 bottle prices.
 *
 * A line with no product_unit_id is a sale from an older till, or a cached
 * catalogue from before units existed. It passes through exactly as it always
 * did: quantity × unit_price.
 *
 * Shared by the counter path and the offline-sync path so the two can never
 * disagree about what a line means.
 */
final class SaleLineResolver
{
    /** @var Collection<int, Product> */
    private Collection $products;

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int|string, array<string, mixed>>  Same keys as $items. Each line carries
     *         quantity (base units), unit_price (per base unit, for the existing column),
     *         line_gross (money before the line discount) and the unit snapshot fields.
     *
     * @throws ValidationException
     */
    public function resolve(Vendor $vendor, array $items): array
    {
        $this->products = Product::where('vendor_id', $vendor->id)
            ->whereIn('id', collect($items)->pluck('product_id')->filter())
            ->with('units')
            ->get()
            ->keyBy('id');

        $lines = [];

        // Price changes at the till are the vendor's choice, and off unless
        // they turn it on. Off, the price a till sends is not believed at all:
        // every line is repriced from the catalogue — the unit's own price, at
        // retail or wholesale as the line says — whatever arrived.
        $catalogueOnly = ! (bool) $vendor->pos_allow_price_change;

        foreach ($items as $key => $item) {
            $line = empty($item['product_unit_id'])
                ? $this->legacyLine($item)
                : $this->unitLine($item);

            $lines[$key] = $catalogueOnly ? $this->atCataloguePrice($line) : $line + ['price_forced' => false];
        }

        return $lines;
    }

    /**
     * The line at the catalogue price, with `price_forced` saying whether
     * that changed what the till sent.
     */
    private function atCataloguePrice(array $line): array
    {
        $product = $this->products->get((int) $line['product_id']);

        // Not this vendor's product: left for the price floor's own check to
        // refuse, exactly as before.
        if (! $product) {
            return $line + ['price_forced' => false];
        }

        if ($line['product_unit_id']) {
            $unit = $product->units->firstWhere('id', (int) $line['product_unit_id']);
            $wholesale = $line['price_type'] === 'wholesale';

            $price = $unit->is_base
                ? (float) ($wholesale && $product->wholesale_price !== null ? $product->wholesale_price : $product->price)
                : (float) ($wholesale && $unit->wholesale_price !== null ? $unit->wholesale_price : $unit->price);

            $forced = abs($price - (float) $line['sold_unit_price']) > 0.004;
            $gross  = $price * (int) $line['unit_qty'];

            return array_merge($line, [
                'sold_unit_price' => $price,
                'unit_price'      => round($gross / max(1, (int) $line['quantity']), 2),
                'line_gross'      => $gross,
                'price_forced'    => $forced,
            ]);
        }

        // A line from a till without units sells in the base unit at retail.
        $price  = (float) $product->price;
        $forced = abs($price - (float) $line['unit_price']) > 0.004;

        return array_merge($line, [
            'unit_price'   => $price,
            'line_gross'   => $price * (int) $line['quantity'],
            'price_forced' => $forced,
        ]);
    }

    /** The products the last resolve() looked up, keyed by id. */
    public function products(): Collection
    {
        return $this->products ?? collect();
    }

    private function legacyLine(array $item): array
    {
        $quantity = (int) $item['quantity'];

        return array_merge($item, [
            'quantity'        => $quantity,
            'line_gross'      => (float) $item['unit_price'] * $quantity,
            'product_unit_id' => null,
            'unit_name'       => null,
            'unit_qty'        => null,
            'sold_unit_price' => null,
            'price_type'      => 'retail',
            'conversion_factor' => 1,
        ]);
    }

    private function unitLine(array $item): array
    {
        $product = $this->products->get((int) $item['product_id']);

        /** @var ProductUnit|null $unit */
        $unit = $product?->units->firstWhere('id', (int) $item['product_unit_id']);

        if (! $product || ! $unit || ! $unit->is_active) {
            throw ValidationException::withMessages([
                'items' => sprintf(
                    '%s is no longer sold in that unit. Remove it and add it again.',
                    $item['product_name'] ?? 'A product',
                ),
            ]);
        }

        $unitQty = (int) ($item['unit_qty'] ?? $item['quantity'] ?? 0);

        if ($unitQty < 1) {
            throw ValidationException::withMessages([
                'items' => sprintf('%s needs a quantity of at least 1.', $product->name),
            ]);
        }

        $factor    = max(1, (int) $unit->conversion_factor);
        $base      = $unitQty * $factor;
        $soldPrice = (float) $item['unit_price'];
        $gross     = $soldPrice * $unitQty;

        // A wholesale price the unit does not have is retail — the till shows
        // it that way, and the floor below is worked out on the same basis.
        $priceType = ($item['price_type'] ?? 'retail') === 'wholesale' && $unit->wholesale_price !== null
            ? 'wholesale'
            : 'retail';

        return array_merge($item, [
            'quantity'          => $base,
            // The existing column means "per base unit". Rounded to kobo; the
            // line's own total and line_gross are the exact figures.
            'unit_price'        => round($gross / $base, 2),
            'line_gross'        => $gross,
            'product_unit_id'   => $unit->id,
            'unit_name'         => $unit->name,
            'unit_qty'          => $unitQty,
            'sold_unit_price'   => $soldPrice,
            'price_type'        => $priceType,
            'conversion_factor' => $factor,
        ]);
    }
}
