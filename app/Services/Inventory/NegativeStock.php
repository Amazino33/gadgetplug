<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\Vendor;

/**
 * Whether a product may be sold below zero stock.
 *
 * The product's own override wins when it has one; otherwise the shop's
 * setting decides. Both default to "no", which is how every shop behaved
 * before the setting existed.
 */
final class NegativeStock
{
    public static function allowed(Product $product, ?Vendor $vendor = null): bool
    {
        if ($product->negative_stock_override !== null) {
            return (bool) $product->negative_stock_override;
        }

        $vendor ??= $product->relationLoaded('vendor') ? $product->vendor : Vendor::find($product->vendor_id);

        return (bool) ($vendor?->allow_negative_stock ?? false);
    }
}
