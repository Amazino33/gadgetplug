<?php

namespace App\Services\Affiliate;

use App\Models\AffiliateSetting;
use App\Models\Product;

// Percentage-only, tiered by category, mirroring RateResolver's resolution
// order exactly via the same shared CategoryTierResolver — never a flat
// naira amount, which would destroy thin-ticket items.
class ResellerDiscountResolver
{
    public function resolveForProduct(Product $product): float
    {
        return CategoryTierResolver::resolve(
            $product,
            'reseller_discount',
            'reseller_discount',
            (float) AffiliateSetting::current()->platform_default_reseller_discount,
        );
    }
}
