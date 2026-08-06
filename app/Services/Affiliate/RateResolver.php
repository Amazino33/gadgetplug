<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateSetting;
use App\Models\Product;

// Resolves the commission rate for a single product: product override wins,
// then category rate, then the platform default. Rate resolution is
// per-product (not per-order) because a single order can span products
// across different categories/overrides — see AffiliateCommissionItem.
//
// Level boost (Prompt 2, Model A): when an affiliate is passed and has a
// level, the resolved base rate is multiplied by that level's rate_value.
// The margin cap this needs (Model A's safety net on thin-margin items)
// requires per-line quantity/cost context this method doesn't have, so it's
// enforced by the caller (CommissionService), not here.
class RateResolver
{
    public function resolveForProduct(Product $product, ?Affiliate $affiliate = null): float
    {
        $baseRate = $this->baseRateForProduct($product);

        if ($affiliate?->level === null) {
            return $baseRate;
        }

        return round($baseRate * (float) $affiliate->level->rate_value, 2);
    }

    private function baseRateForProduct(Product $product): float
    {
        return CategoryTierResolver::resolve(
            $product,
            'commission_rate',
            'commission_rate',
            (float) AffiliateSetting::current()->platform_default_rate,
        );
    }
}
