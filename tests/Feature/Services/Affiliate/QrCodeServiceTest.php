<?php

use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\QrCodeService;

function makeQrTestProduct(array $attrs = []): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'QR Test Store ' . uniqid()]);
    $category = Category::create(['name' => 'QR Test Category ' . uniqid()]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'QR Test Product',
        'price'          => 1500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $attrs));
}

test('the referral link url points at the existing /r/{code} route with no query string', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $url = app(QrCodeService::class)->referralLinkUrl($affiliate);

    expect($url)->toBe(route('affiliate.click', ['code' => $affiliate->code]));
});

test('the product link url carries the code and the product slug as ?to=', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $product   = makeQrTestProduct();

    $url = app(QrCodeService::class)->productLinkUrl($affiliate, $product);

    expect($url)->toBe(route('affiliate.click', ['code' => $affiliate->code, 'to' => $product->slug]));
});

test('the referral QR renders a valid SVG document', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $svg = app(QrCodeService::class)->referralQrSvg($affiliate);

    expect($svg)->toContain('<svg');
});

test('requesting the same referral QR twice reuses the cached media instead of regenerating', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $service   = app(QrCodeService::class);

    // Each call uses a freshly-loaded Affiliate instance, mirroring how a
    // real page load fetches the model anew each request — Spatie's media
    // relation is cached per-instance, so reusing the same in-memory object
    // across calls isn't representative of real usage.
    $first  = $service->referralQrSvg($affiliate->fresh());
    $second = $service->referralQrSvg($affiliate->fresh());

    expect($first)->toBe($second)
        ->and($affiliate->fresh()->getMedia('qr-codes')->count())->toBe(1);
});

test('a product QR is cached separately from the referral QR and from other products', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $productA  = makeQrTestProduct(['name' => 'Product A']);
    $productB  = makeQrTestProduct(['name' => 'Product B']);
    $service   = app(QrCodeService::class);

    $service->referralQrSvg($affiliate);
    $service->productQrSvg($affiliate, $productA);
    $service->productQrSvg($affiliate, $productB);

    expect($affiliate->fresh()->getMedia('qr-codes')->count())->toBe(3);
});

test('a cached QR whose stored url no longer matches the current one is regenerated, not accumulated', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $product   = makeQrTestProduct();
    $service   = app(QrCodeService::class);

    $service->productQrSvg($affiliate, $product);
    $original = $affiliate->fresh()->getMedia('qr-codes')->first();
    expect($affiliate->fresh()->getMedia('qr-codes')->count())->toBe(1);

    // Simulate a stale entry (e.g. a code or slug that changed since caching)
    // by forging a mismatched stored url under the same qr_key.
    $original->setCustomProperty('url', 'https://stale.example.com/old-link');
    $original->save();

    $service->productQrSvg($affiliate->fresh(), $product);

    $current = $affiliate->fresh()->getMedia('qr-codes')->firstWhere('custom_properties.qr_key', "product-{$product->id}");

    // Still exactly one cached file for this product (stale one replaced, not
    // accumulated), and it now reflects the correct current url again.
    expect($affiliate->fresh()->getMedia('qr-codes')->count())->toBe(1)
        ->and($current->getCustomProperty('url'))->toBe($service->productLinkUrl($affiliate, $product));
});
