<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * A product whose NAME is set at creation, so its slug derives from it.
 *
 * Renaming afterwards would not do: doNotGenerateSlugsOnUpdate() leaves the
 * original slug in place, so a renamed product never collides — which is how a
 * first attempt at this test passed while the bug was live.
 */
function namedProduct(Vendor $vendor, string $name, float $price = 10000, int $stock = 5): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'VendorLink Cat'])->id,
        'name'           => $name,
        'price'          => $price,
        'cost_price'     => $price * 0.6,
        'stock_quantity' => $stock,
        'status'         => 'published',
    ]);
}

test('a resold listing is reachable at its product URL', function () {
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);

    $source = namedProduct($supplier, 'OTW 323P');
    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    $listing = Product::linked()->firstOrFail();

    // Before the binding override this 404'd: the slug resolved to the
    // supplier's row, whose shop is hidden, and the detail page refused it —
    // while the listing sat in stock and reachable by nobody.
    $this->get(route('product.show', $listing->slug))->assertOk();
});

test('the colliding slug is the accepted state, and routing is what resolves it', function () {
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier);

    $source = namedProduct($supplier, 'OTW 323P');
    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    $listing = Product::linked()->firstOrFail();

    // Slugs are unique per vendor by design, so these two genuinely share one.
    // The fix does not separate them — it decides which one a URL means.
    expect($listing->slug)->toBe($source->fresh()->slug)
        ->and($listing->id)->toBeGreaterThan($source->id);

    $resolved = (new Product())->resolveRouteBinding($listing->slug);

    // The visible one wins over the lower id.
    expect($resolved->id)->toBe($listing->id);
});

test('two ordinary vendors sharing a product name no longer hide each other', function () {
    // The older latent case this shares a cause with, and which VendorLink only
    // made unavoidable rather than created.
    $hidden = linkVendor('Hidden Shop', online: false);
    $open = linkVendor('Open Shop');

    $hiddensProduct = namedProduct($hidden, 'iPhone 15 Pro');
    $opensProduct = namedProduct($open, 'iPhone 15 Pro');

    expect($opensProduct->slug)->toBe($hiddensProduct->slug);

    $this->get(route('product.show', $opensProduct->slug))->assertOk();

    expect((new Product())->resolveRouteBinding($opensProduct->slug)->id)->toBe($opensProduct->id);
});

test('a product nobody can see still reaches the page, which refuses it there', function () {
    $hidden = linkVendor('Hidden Shop', online: false);
    $product = namedProduct($hidden, 'Invisible Thing');

    // The fallback matters: binding must not start returning null for rows that
    // used to resolve, or a 404 decision quietly moves out of the page that
    // owns it and into the router.
    expect((new Product())->resolveRouteBinding($product->slug)?->id)->toBe($product->id);

    $this->get(route('product.show', $product->slug))->assertNotFound();
});
