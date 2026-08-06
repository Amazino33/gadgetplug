<?php

use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\AttributionService;

function makeClickTestProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Click Test Store ' . uniqid()]);
    $category = Category::create(['name' => 'Click Test Category ' . uniqid()]);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Click Test Product',
        'price'          => 1000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);
}

test('visiting the plain referral link logs a click, sets the cookie, and redirects home', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $response = $this->get("/r/{$affiliate->code}");

    $response->assertRedirect(route('home'));
    $response->assertCookie(AttributionService::COOKIE_NAME, $affiliate->code);

    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(1);
});

test('visiting a product deep-link logs a click, sets the cookie, and redirects to that product page', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
    $product   = makeClickTestProduct();

    $response = $this->get("/r/{$affiliate->code}?to={$product->slug}");

    $response->assertRedirect(route('product.show', ['product' => $product]));
    $response->assertCookie(AttributionService::COOKIE_NAME, $affiliate->code);

    $click = AffiliateClick::where('affiliate_id', $affiliate->id)->first();
    expect($click)->not->toBeNull()
        ->and($click->landing_url)->toBe($product->slug);
});

test('a deep-link with an unknown product slug falls back to home without erroring', function () {
    $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());

    $response = $this->get("/r/{$affiliate->code}?to=does-not-exist-at-all");

    $response->assertRedirect(route('home'));
    expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(1);
});

test('an unknown affiliate code redirects home and logs nothing', function () {
    $response = $this->get('/r/NOT-A-REAL-CODE');

    $response->assertRedirect(route('home'));
    expect(AffiliateClick::count())->toBe(0);
});
