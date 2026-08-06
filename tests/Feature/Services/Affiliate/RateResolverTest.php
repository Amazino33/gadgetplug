<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\User;
use App\Services\Affiliate\RateResolver;

function makeRateResolverProduct(array $productAttrs = [], array $categoryAttrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Rate Resolver Store']);
    $category = Category::create(array_merge(['name' => 'Rate Resolver Category ' . uniqid()], $categoryAttrs));

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Rate Resolver Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $productAttrs));
}

test('a product override rate wins over category rate and platform default', function () {
    $product = makeRateResolverProduct(
        productAttrs: ['commission_rate' => 12.5],
        categoryAttrs: ['commission_rate' => 8.0],
    );

    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category')))->toBe(12.5);
});

test('category rate wins over the platform default when no product override exists', function () {
    $product = makeRateResolverProduct(categoryAttrs: ['commission_rate' => 8.0]);

    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category')))->toBe(8.0);
});

test('platform default is used when neither product override nor category rate exist', function () {
    \App\Models\AffiliateSetting::current()->update(['platform_default_rate' => 5.0]);

    $product = makeRateResolverProduct();

    expect(app(RateResolver::class)->resolveForProduct($product->fresh('category')))->toBe(5.0);
});
