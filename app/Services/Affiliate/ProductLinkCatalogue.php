<?php

namespace App\Services\Affiliate;

use App\Models\Product;
use App\Services\VendorLink\LinkedPricing;

// Every product an affiliate can link to, shaped for the browser.
//
// The affiliate page ships this once, inlined into the page, and searches it
// in Alpine — no request per keystroke. ~465 products is ~90KB of JSON, so the
// whole catalogue is cheaper than a round-trip that re-renders the dashboard.
//
// `name` and `sku` are there to match on only; the page shows `displayName`,
// the name a customer sees on the product, so the affiliate finds a product by
// the name they've seen on the storefront.
class ProductLinkCatalogue
{
    /**
     * @return list<array{id: int, name: string, displayName: string, sku: string, slug: string, price: float, thumb: string}>
     */
    public function forBrowser(): array
    {
        // withCatalogue() eager-loads the resold-listing source and its photos,
        // so displayName() and the thumbnail resolve without a query per row.
        return Product::visibleOnline()
            ->withCatalogue()
            ->get()
            ->map(fn (Product $product) => [
                'id'          => $product->id,
                'name'        => (string) $product->name,
                'displayName' => $product->displayName(),
                'sku'         => (string) $product->sku,
                'slug'        => (string) $product->slug,
                'price'       => $this->price($product),
                'thumb'       => $product->catalogueImageUrl('thumb'),
            ])
            ->filter(fn (array $row) => $row['slug'] !== '')
            ->sortBy('displayName', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * What the product page will quote.
     *
     * The price accessor reads a resold listing's supplier through
     * LinkedPricing::priceFor(), which looks the link and source up afresh —
     * two queries per listing, eager loads or not. catalogueSource() has
     * already resolved the same pair from the eager loads under the same
     * rules (link usable, source published and his), so price from that,
     * through the same arithmetic.
     */
    private function price(Product $product): float
    {
        if ($product->isLinked() && ! $product->has_custom_price) {
            $source = $product->catalogueSource();

            if ($source && $product->supplierLink) {
                return LinkedPricing::retail((float) $source->price, $product->supplierLink);
            }
        }

        return (float) $product->price;
    }
}
