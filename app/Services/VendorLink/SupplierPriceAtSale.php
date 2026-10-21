<?php

declare(strict_types=1);

namespace App\Services\VendorLink;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplierLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * What the supplier charged for a resold line when it was sold — for booking
 * a payable after the fact (the one-off backfill), when "his price now" is not
 * the price of the sale.
 *
 * In order, the first that exists:
 *   1. the line's own unit_cost — a snapshot taken at checkout (wallet
 *      purchases record the listing's resolved cost there);
 *   2. the supplier's price on the sale date, rebuilt from his product's
 *      activity log (every price change is logged with its old and new value);
 *   3. his price today — the fallback, reported as such so it can be checked.
 */
class SupplierPriceAtSale
{
    public const FROM_SNAPSHOT = 'line snapshot';

    public const FROM_HISTORY = 'supplier price history';

    public const FROM_TODAY = "today's price (fallback)";

    /**
     * @return array{cost: ?float, source: string, today: ?float, listing: ?float}
     *   cost: what to book per unit (null when nothing at all is known);
     *   today: his price now; listing: the listing's last recorded cost_price.
     */
    public function resolve(OrderItem $item, CarbonInterface $soldAt): array
    {
        $listing = $item->product;
        $link = $listing?->supplier_link_id ? SupplierLink::find($listing->supplier_link_id) : null;
        $source = $link && $listing->source_product_id ? SupplierCatalogue::find($link, (int) $listing->source_product_id) : null;

        $today = $source ? (float) $source->price : null;
        $listingCost = $listing?->cost_price !== null ? (float) $listing->cost_price : null;

        $base = ['today' => $today, 'listing' => $listingCost];

        if ($item->unit_cost !== null && (float) $item->unit_cost > 0) {
            return ['cost' => (float) $item->unit_cost, 'source' => self::FROM_SNAPSHOT] + $base;
        }

        $historical = $source ? $this->priceOn($source, $soldAt) : null;

        if ($historical !== null && $historical > 0) {
            return ['cost' => $historical, 'source' => self::FROM_HISTORY] + $base;
        }

        // Same last resort the live booking has always used: his price now,
        // else the listing's last resolved cost.
        return ['cost' => $today ?? $listingCost, 'source' => self::FROM_TODAY] + $base;
    }

    /**
     * The product's price as of $at, from its activity log: the last logged
     * price at or before $at; failing that, the "old" side of the first change
     * after it (the price had not moved before then). Null with no history.
     */
    public function priceOn(Product $product, CarbonInterface $at): ?float
    {
        $changes = Activity::query()
            ->where('subject_type', $product->getMorphClass())
            ->where('subject_id', $product->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Activity $a) => data_get($a->properties, 'attributes.price') !== null);

        $before = $changes->filter(fn (Activity $a) => Carbon::parse($a->created_at)->lte($at))->last();

        if ($before) {
            return (float) data_get($before->properties, 'attributes.price');
        }

        $after = $changes->first(fn (Activity $a) => data_get($a->properties, 'old.price') !== null);

        return $after ? (float) data_get($after->properties, 'old.price') : null;
    }
}
