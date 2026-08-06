<?php

namespace App\Services\Affiliate;

use App\Models\Product;

// Shared product-override -> category -> platform-default fallback chain.
// Extracted so commission rates (RateResolver) and reseller discounts
// (ResellerDiscountResolver) resolve identically instead of each carrying
// their own copy of the same three-step algorithm.
class CategoryTierResolver
{
    public static function resolve(Product $product, string $productColumn, string $categoryColumn, float $platformDefault): float
    {
        if ($product->{$productColumn} !== null) {
            return (float) $product->{$productColumn};
        }

        $categoryValue = $product->category?->{$categoryColumn};

        if ($categoryValue !== null) {
            return (float) $categoryValue;
        }

        return $platformDefault;
    }
}
