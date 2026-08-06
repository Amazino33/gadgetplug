<?php

use App\Models\Affiliate;
use App\Models\AffiliateLevel;
use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\RateResolver;

function makeLevelBoostProduct(array $productAttrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Level Boost Store ' . uniqid()]);
    $category = Category::create(['name' => 'Level Boost Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Level Boost Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $productAttrs));
}

function makeLevelBoostAffiliate(?AffiliateLevel $level = null): Affiliate
{
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    if ($level) {
        $affiliate->update(['affiliate_level_id' => $level->id, 'level_achieved_at' => now()]);
    }

    return $affiliate->fresh('level');
}

test('an affiliate with no level gets the plain unboosted base rate', function () {
    AffiliateSetting::current()->update(['platform_default_rate' => 5.0]);

    $product   = makeLevelBoostProduct();
    $affiliate = makeLevelBoostAffiliate();

    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category'), $affiliate))->toBe(5.0);
});

test('an affiliate with a level multiplies the resolved base rate', function () {
    AffiliateSetting::current()->update(['platform_default_rate' => 10.0]);

    $level     = AffiliateLevel::create(['name' => 'Gold', 'target' => 0, 'rate_value' => 1.2, 'sort_order' => 1]);
    $product   = makeLevelBoostProduct();
    $affiliate = makeLevelBoostAffiliate($level);

    // 10% base * 1.2 multiplier = 12%
    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category'), $affiliate))->toBe(12.0);
});

test('calling resolveForProduct without an affiliate still returns the plain base rate', function () {
    AffiliateSetting::current()->update(['platform_default_rate' => 7.0]);

    $product = makeLevelBoostProduct();

    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category')))->toBe(7.0);
});
