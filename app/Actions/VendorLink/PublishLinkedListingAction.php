<?php

declare(strict_types=1);

namespace App\Actions\VendorLink;

use App\Models\Product;
use App\Models\SupplierLink;
use App\Services\VendorLink\Rounding\RoundingRules;
use App\Services\VendorLink\SupplierCatalogue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publish a supplier's product as a listing in the reseller's own catalogue.
 *
 * The listing is a real product row, so the storefront, cart, checkout, slugs
 * and the pixel all treat it like any other product. What makes it a linked
 * listing is only that it points at a source.
 *
 * Everything a customer sees stays live on the supplier's product: price,
 * stock, photos and description are all read from the source at display time
 * (Product::catalogueSource()). Nothing presentational is copied, so a supplier
 * fixing a photo fixes it on every reseller's listing at once. The listing keeps
 * only its own internal name, brand and category for the reseller's own panel.
 * (This reversed the earlier "copied once, reseller owns it" rule in Oct 2026;
 * vendorlink:purge-copied-media clears what that rule left behind.)
 *
 * The listing deliberately holds NO stock of its own. products.stock_quantity is
 * authoritatively recomputed from the per-store rows, so a mirrored quantity
 * would be overwritten by the next stock event and would be a lie until then —
 * and worse, it would put units into the reseller's inventory and till that
 * nobody can actually hand over. Stock resolves from the source at read time.
 */
class PublishLinkedListingAction
{
    /**
     * @param  array<int, int>  $sourceProductIds
     * @return array{published: int, updated: int, skipped: int}
     */
    public function execute(SupplierLink $link, array $sourceProductIds): array
    {
        if (! $link->isUsable()) {
            throw new RuntimeException('That supplier link is not active.');
        }

        $counts = ['published' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($sourceProductIds as $sourceId) {
            $source = SupplierCatalogue::find($link, (int) $sourceId);

            // Silently skipping rather than throwing: a bulk publish of two
            // hundred products must not fail wholesale because one was deleted
            // between listing the catalogue and pressing the button.
            if (! $source) {
                $counts['skipped']++;

                continue;
            }

            $existing = Product::where('supplier_link_id', $link->id)
                ->where('source_product_id', $source->id)
                ->first();

            if ($existing) {
                $this->refresh($existing, $source, $link);
                $counts['updated']++;

                continue;
            }

            $this->publish($source, $link);
            $counts['published']++;
        }

        return $counts;
    }

    private function publish(Product $source, SupplierLink $link): Product
    {
        return DB::transaction(function () use ($source, $link) {
            $listing = Product::create([
                'vendor_id'         => $link->reseller_vendor_id,
                'source_product_id' => $source->id,
                'supplier_link_id'  => $link->id,
                'category_id'       => $source->category_id,
                'name'              => $source->name,
                'brand'             => $source->brand,
                // No description and no photos: both resolve live from the
                // source. See the class docblock.

                // A mirror of the marked-up price, kept so the catalogue can
                // sort and filter on it in SQL. Price has no invariant behind
                // it — nothing recomputes it — so unlike stock it is safe to
                // hold here. The accessor stays authoritative for money.
                'price'      => $this->retailPrice($source, $link),
                'cost_price' => $source->price,

                // Holds nothing. See the class docblock.
                'stock_quantity' => 0,

                'status'      => 'published',
                'show_online' => true,
                // Never on a till: there is no stock behind it to hand over,
                // and the counter sells what is physically present.
                'show_in_pos' => false,
            ]);

            return $listing;
        });
    }

    /**
     * Re-publishing an already-linked product.
     *
     * Updates the mirrored price and re-points the link. Never touches name,
     * description or photos: the internal name is the reseller's, and photos
     * and description are read live from the source, so there is nothing to
     * refresh.
     */
    private function refresh(Product $listing, Product $source, SupplierLink $link): void
    {
        $data = [
            'supplier_link_id' => $link->id,
            'cost_price'       => $source->price,
        ];

        if (! $listing->has_custom_price) {
            $data['price'] = $this->retailPrice($source, $link);
        }

        $listing->update($data);
    }

    /** Supplier price plus the link's markup, tidied by the link's rounding rule. */
    private function retailPrice(Product $source, SupplierLink $link): float
    {
        $marked = (float) $source->price * (1 + ((float) $link->markup_percent / 100));

        return RoundingRules::make($link->rounding_rule)->apply($marked);
    }
}
