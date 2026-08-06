<?php

use App\Models\AffiliateSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\ResellerDiscountResolver;

function makeResellerDiscountProduct(array $productAttrs = [], array $categoryAttrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Reseller Discount Store']);
    $category = Category::create(array_merge(['name' => 'Reseller Discount Category ' . uniqid()], $categoryAttrs));

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Reseller Discount Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $productAttrs));
}

test('a product override discount wins over category discount and platform default', function () {
    $product = makeResellerDiscountProduct(
        productAttrs: ['reseller_discount' => 15.0],
        categoryAttrs: ['reseller_discount' => 8.0],
    );

    expect(app(ResellerDiscountResolver::class)->resolveForProduct($product->fresh('category')))->toBe(15.0);
});

test('category discount wins over the platform default when no product override exists', function () {
    $product = makeResellerDiscountProduct(categoryAttrs: ['reseller_discount' => 8.0]);

    expect(app(ResellerDiscountResolver::class)->resolveForProduct($product->fresh('category')))->toBe(8.0);
});

test('platform default reseller discount is used when neither product override nor category discount exist', function () {
    AffiliateSetting::current()->update(['platform_default_reseller_discount' => 12.0]);

    $product = makeResellerDiscountProduct();

    expect(app(ResellerDiscountResolver::class)->resolveForProduct($product->fresh('category')))->toBe(12.0);
});
