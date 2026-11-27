<?php

declare(strict_types=1);

namespace App\Services\StockTake;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Vendor;
use App\Services\Purchases\PurchaseCatalogue;
use Illuminate\Support\Collection;

/**
 * Finding products from the Stock Take phone screens: by barcode (a unit's
 * own code included), by SKU, or by name.
 *
 * The finding is PurchaseCatalogue's — the same products a delivery can be
 * received into, so services and resold listings are left out. The shape sent
 * to the phone is this class's own, and deliberately carries no stock, cost or
 * price: a counter must never see what the system thinks is on the shelf, and
 * nothing on these screens needs money.
 */
final class StockTakeCatalogue
{
    public function __construct(private readonly PurchaseCatalogue $catalogue) {}

    /** @return array{0: Product, 1: int|null}|null  the product, and the unit whose own barcode was scanned */
    public function lookup(Vendor $vendor, string $code): ?array
    {
        return $this->catalogue->lookup($vendor, $code);
    }

    /** @return Collection<int, Product> */
    public function search(Vendor $vendor, string $term, int $limit = 15): Collection
    {
        return $this->catalogue->search($vendor, $term, $limit);
    }

    /**
     * What the phone needs to show and count a product. Nothing about stock.
     *
     * @return array<string, mixed>
     */
    public function format(Product $product, ?int $scannedUnitId = null): array
    {
        $product->loadMissing('units');

        return [
            'id'              => $product->id,
            'name'            => $product->name,
            'sku'             => $product->sku,
            'barcode'         => $product->barcode,
            'image'           => $product->getFirstMediaUrl('product-images', 'preview') ?: null,
            'base_unit'       => $product->base_unit ?: 'Piece',
            // Largest first: how a counter reads a shelf (3 crates + 5 bottles).
            'units'           => $product->units
                ->where('is_active', true)
                ->sortByDesc('conversion_factor')
                ->map(fn (ProductUnit $u) => [
                    'id'      => (int) $u->id,
                    'name'    => $u->name,
                    'factor'  => max(1, (int) $u->conversion_factor),
                    'is_base' => (bool) $u->is_base,
                ])
                ->values()
                ->all(),
            'scanned_unit_id' => $scannedUnitId,
        ];
    }
}
