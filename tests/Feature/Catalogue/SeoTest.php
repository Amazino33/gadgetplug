<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Http\Controllers\Storefront\SitemapController;
use App\Models\Product;
use App\Models\ProductSlugRedirect;
use App\Models\Vendor;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../VendorLink/Helpers.php';

uses(RefreshDatabase::class);

// The URL follows the online name (old URLs 301), and the product page tells
// search engines what it is: canonical on the storefront host, JSON-LD, and a
// sitemap of exactly what customers can see.

function seoProduct(array $attributes = []): Product
{
    $vendor = Vendor::factory()->onlineSalesEnabled()->create();

    return ProductFactory::new()->withPhoto()->create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'name'           => 'ITEL 2160 OLD ONE',
        'brand'          => 'itel',
        'sku'            => 'IT-2160',
        'price'          => 9500,
        'stock_quantity' => 4,
    ], $attributes));
}

beforeEach(function () {
    config(['app.storefront_url' => 'https://gadgetplug.ng']);
    Cache::forget(SitemapController::CACHE_KEY);
});

describe('slugs follow the online name', function () {
    test('setting an online name moves the URL, and the old URL 301s to it', function () {
        $product = seoProduct();
        $old = $product->slug;

        expect($old)->toBe('itel-2160-old-one');

        $product->update(['online_name' => 'itel 2160 Dual SIM Keypad Phone – Black']);
        $new = $product->fresh()->slug;

        expect($new)->toBe('itel-2160-dual-sim-keypad-phone-black')
            ->and(ProductSlugRedirect::where('product_id', $product->id)->pluck('old_slug')->all())->toBe([$old]);

        $this->get('/product/'.$old.'?ref=wa')
            ->assertStatus(301)
            ->assertRedirect(route('product.show', $new).'?ref=wa');

        $this->get(route('product.show', $new))->assertOk();
    });

    test('changing the online name again keeps every old URL working', function () {
        $product = seoProduct(['online_name' => 'First Online Name']);
        $first = $product->slug;

        $product->update(['online_name' => 'Second Online Name']);
        $product->update(['online_name' => 'Third Online Name']);
        $current = $product->fresh()->slug;

        $this->get('/product/'.$first)->assertRedirect(route('product.show', $current));
        $this->get('/product/second-online-name')->assertRedirect(route('product.show', $current));
    });

    test('a product created with an online name is slugged from it', function () {
        expect(seoProduct(['online_name' => 'Tecno Spark 20 128GB'])->slug)->toBe('tecno-spark-20-128gb');
    });

    test('changing the internal name never touches the slug', function () {
        $product = seoProduct(['online_name' => 'Clean Online Name']);
        $slug = $product->slug;

        $product->update(['name' => 'RENAMED ON THE SHELF']);

        expect($product->fresh()->slug)->toBe($slug)
            ->and(ProductSlugRedirect::count())->toBe(0);
    });

    test('clearing the online name leaves the URL where it is', function () {
        $product = seoProduct(['online_name' => 'Clean Online Name']);
        $slug = $product->slug;

        $product->update(['online_name' => null]);

        expect($product->fresh()->slug)->toBe($slug);
    });

    test('two products of one vendor with the same online name get distinct slugs', function () {
        $a = seoProduct();
        $b = ProductFactory::new()->withPhoto()->create(['vendor_id' => $a->vendor_id, 'name' => 'ANOTHER ITEL']);

        $a->update(['online_name' => 'itel Keypad Phone']);
        $b->update(['online_name' => 'itel Keypad Phone']);

        expect($a->fresh()->slug)->toBe('itel-keypad-phone')
            ->and($b->fresh()->slug)->toBe('itel-keypad-phone-1');
    });

    test('a supplier setting an online name never re-slugs a reseller\'s listing', function () {
        $reseller = linkVendor('Reseller');
        $supplier = linkVendor('Wholesaler', online: false);
        $link = makeLink($reseller, $supplier);
        $source = linkProduct($supplier);
        ProductFactory::attachPhoto($source);
        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);
        $listing = Product::linked()->firstOrFail();
        $listingSlug = $listing->slug;

        $source->update(['online_name' => 'Supplier Online Name']);

        expect($listing->fresh()->slug)->toBe($listingSlug)
            ->and($listing->fresh()->displayName())->toBe('Supplier Online Name');

        // And the listing's page is canonical to its OWN URL.
        $this->get(route('product.show', $listingSlug))
            ->assertSee('<link rel="canonical" href="https://gadgetplug.ng/product/'.$listingSlug.'">', false);
    });
});

describe('the product page speaks to search engines', function () {
    test('title, meta description and canonical on the storefront host', function () {
        $product = seoProduct([
            'online_name'      => 'itel 2160 Dual SIM Keypad Phone – Black',
            'meta_description' => 'Tough dual SIM keypad phone with a week of battery.',
        ]);

        $this->get(route('product.show', $product->slug))
            ->assertSee('<title>itel 2160 Dual SIM Keypad Phone – Black — GadgetPlug</title>', false)
            ->assertSee('<meta name="description" content="Tough dual SIM keypad phone with a week of battery.">', false)
            ->assertSee('<link rel="canonical" href="https://gadgetplug.ng/product/'.$product->slug.'">', false)
            ->assertSee('<meta property="og:url"         content="https://gadgetplug.ng/product/'.$product->slug.'">', false);
    });

    test('JSON-LD Product carries the online name, price in NGN and live availability', function () {
        $product = seoProduct(['online_name' => 'itel 2160 Dual SIM Keypad Phone – Black']);

        $ld = productJsonLd($this->get(route('product.show', $product->slug))->getContent());

        expect($ld['@type'])->toBe('Product')
            ->and($ld['name'])->toBe('itel 2160 Dual SIM Keypad Phone – Black')
            ->and($ld['sku'])->toBe('IT-2160')
            ->and($ld['brand'])->toBe(['@type' => 'Brand', 'name' => 'itel'])
            ->and($ld['url'])->toBe('https://gadgetplug.ng/product/'.$product->slug)
            ->and($ld['image'])->toHaveCount(1)
            ->and($ld['offers']['price'])->toBe('9500.00')
            ->and($ld['offers']['priceCurrency'])->toBe('NGN')
            ->and($ld['offers']['availability'])->toBe('https://schema.org/InStock')
            ->and($ld['offers']['seller']['name'])->toBe($product->vendor->name);
    });

    test('JSON-LD says OutOfStock when nothing can be bought', function () {
        $product = seoProduct(['stock_quantity' => 0]);

        $ld = productJsonLd($this->get(route('product.show', $product->slug))->getContent());

        expect($ld['offers']['availability'])->toBe('https://schema.org/OutOfStock');
    });

    test('other storefront pages are canonical on the storefront host too', function () {
        $this->get(route('home'))->assertSee('<link rel="canonical" href="https://gadgetplug.ng/">', false);
        $this->get(route('home', ['search' => 'phone']))->assertSee('<link rel="canonical" href="https://gadgetplug.ng/">', false);
    });
});

describe('/sitemap.xml', function () {
    test('lists only online-visible products, on the storefront host', function () {
        $visible = seoProduct(['online_name' => 'Visible Phone']);
        $noPhoto = ProductFactory::new()->create(['vendor_id' => $visible->vendor_id, 'name' => 'No Photo Phone']);
        $draft = ProductFactory::new()->withPhoto()->create(['vendor_id' => $visible->vendor_id, 'name' => 'Draft Phone', 'status' => 'draft']);
        $hiddenVendor = ProductFactory::new()->withPhoto()->create([
            'vendor_id' => Vendor::factory()->create(['online_sales_enabled' => false])->id,
            'name'      => 'Switched Off Phone',
        ]);

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = $response->getContent();

        expect($xml)->toContain('<loc>https://gadgetplug.ng/product/'.$visible->slug.'</loc>')
            ->toContain('<loc>https://gadgetplug.ng/</loc>')
            ->toContain('<loc>https://gadgetplug.ng/stores</loc>')
            ->toContain('<loc>https://gadgetplug.ng/store/'.$visible->vendor->slug.'</loc>')
            ->toContain('<loc>https://gadgetplug.ng/?category='.$visible->category->slug.'</loc>')
            ->not->toContain('/product/'.$noPhoto->slug.'<')
            ->not->toContain('/product/'.$draft->slug.'<')
            ->not->toContain('/product/'.$hiddenVendor->slug.'<')
            ->not->toContain('/store/'.$hiddenVendor->vendor->slug.'<')
            ->not->toContain(rtrim(config('app.url'), '/').'/product/');

        expect(simplexml_load_string($xml))->not->toBeFalse();
    });

    test('robots.txt points at it', function () {
        expect(file_get_contents(public_path('robots.txt')))->toContain('Sitemap: https://gadgetplug.ng/sitemap.xml');
    });
});

/** The decoded JSON-LD block from a page. */
function productJsonLd(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    expect($m)->not->toBeEmpty();

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}
