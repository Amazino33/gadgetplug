<?php

declare(strict_types=1);

namespace App\Services\VendorLink;

use App\Models\Product;
use App\Models\SupplierLink;
use App\Services\VendorLink\Rounding\RoundingRules;

/**
 * What a linked listing costs and whether it can be sold, answered from the
 * supplier.
 *
 * The arithmetic lives here rather than in three places — the publish action,
 * the browse screen and the model accessor all ask the same question, and a
 * markup computed two ways eventually disagrees with itself.
 *
 * Reads only. Nothing in this class writes to the supplier's product, his stock
 * or his account: he is running his shop as before and does not know the online
 * channel exists.
 */
class LinkedPricing
{
    /** Supplier price plus the link's markup, tidied by the link's rounding rule. */
    public static function retail(float $supplierPrice, SupplierLink $link): float
    {
        $marked = $supplierPrice * (1 + ((float) $link->markup_percent / 100));

        return RoundingRules::make($link->rounding_rule)->apply($marked);
    }

    /**
     * A listing's live retail price, or null when it cannot be resolved.
     *
     * Null is returned rather than a guess whenever the source is gone or the
     * link has been switched off. The caller falls back to the price stored on
     * the listing, which is the last resolved figure — stale, but a real price
     * somebody set, and far better than showing a customer zero.
     */
    public static function priceFor(Product $listing): ?float
    {
        [$source, $link] = self::resolve($listing);

        if (! $source || ! $link) {
            return null;
        }

        return self::retail((float) $source->price, $link);
    }

    /**
     * Units the supplier has available, which is what the listing can sell.
     *
     * His reservations count against it: stock he has promised to one of his own
     * online orders is not stock this listing can also sell. Zero whenever the
     * source is gone or the link is off, which reads as out of stock — the safe
     * direction, since the alternative is taking an order nobody can fill.
     */
    public static function stockFor(Product $listing): int
    {
        [$source, $link] = self::resolve($listing);

        if (! $source || ! $link) {
            return 0;
        }

        return max(0, (int) $source->stock_quantity - (int) $source->reserved_stock);
    }

    /**
     * The source product and its link, read past tenancy.
     *
     * The source belongs to a different vendor, so inside the panel the tenancy
     * global scope would resolve it to null and every linked listing would
     * quietly price at nothing. Read through the one gateway that is allowed to
     * cross that line.
     *
     * @return array{0: ?Product, 1: ?SupplierLink}
     */
    private static function resolve(Product $listing): array
    {
        if (! $listing->source_product_id || ! $listing->supplier_link_id) {
            return [null, null];
        }

        $link = SupplierLink::find($listing->supplier_link_id);

        // An inactive link stops resolving. Deactivating is how an arrangement
        // ends, and a listing must not keep quoting a supplier's price after it.
        if (! $link || ! $link->isUsable()) {
            return [null, null];
        }

        return [SupplierCatalogue::find($link, (int) $listing->source_product_id), $link];
    }
}
