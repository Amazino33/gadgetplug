<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Affiliate\AttributionService;
use App\Services\Affiliate\ProductLinkCatalogue;
use App\Services\Affiliate\QrCodeService;
use Database\Factories\ProductFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

require_once __DIR__.'/VendorLink/Helpers.php';

// The affiliate page's product links: the catalogue it searches in the
// browser, the link it builds there, the on-demand QR, and old links that
// still deep-link after a product's online name (and so its slug) changes.

function linkableProduct(array $attributes = []): Product
{
    $vendor = Vendor::factory()->onlineSalesEnabled()->create();

    return ProductFactory::new()->withPhoto()->create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'name'           => 'SHELF NAME '.Str::upper(Str::random(5)),
        'sku'            => 'SKU-'.Str::upper(Str::random(5)),
        'price'          => 12500,
        'stock_quantity' => 3,
        'status'         => 'published',
    ], $attributes));
}

/** A reseller's listing of a supplier's product, as customers see it. */
function resoldListing(string $supplierOnlineName): Product
{
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $source = linkProduct($supplier);
    ProductFactory::attachPhoto($source);
    $source->update(['online_name' => $supplierOnlineName]);

    app(PublishLinkedListingAction::class)->execute(makeLink($reseller, $supplier), [$source->id]);

    return Product::linked()->where('source_product_id', $source->id)->firstOrFail();
}

function signedInAffiliate(): Affiliate
{
    $user = User::factory()->create();
    test()->actingAs($user);

    return Affiliate::findOrCreateForUser($user);
}

describe('the catalogue the page searches', function () {
    test('each visible product carries the name customers see, plus what it is matched on', function () {
        $product = linkableProduct([
            'name'        => 'TECNO SPK20 128 BLK',
            'online_name' => 'Tecno Spark 20 128GB Black',
            'sku'         => 'TS20-128',
        ]);

        $rows = app(ProductLinkCatalogue::class)->forBrowser();

        expect($rows)->toHaveCount(1)
            ->and(array_keys($rows[0]))->toBe(['id', 'name', 'displayName', 'sku', 'slug', 'price', 'thumb'])
            ->and($rows[0])->toMatchArray([
                'id'          => $product->id,
                'name'        => 'TECNO SPK20 128 BLK',
                'displayName' => 'Tecno Spark 20 128GB Black',
                'sku'         => 'TS20-128',
                'slug'        => $product->slug,
                'price'       => 12500.0,
            ]);
    });

    test('a resold listing is shown under its supplier\'s online name', function () {
        $listing = resoldListing('Supplier Online Name');

        $row = collect(app(ProductLinkCatalogue::class)->forBrowser())->firstWhere('id', $listing->id);

        expect($row)->not->toBeNull()
            ->and($row['displayName'])->toBe('Supplier Online Name')
            ->and($row['slug'])->toBe($listing->slug)
            // Priced from the eager loads, but the same figure the product page quotes.
            ->and($row['price'])->toBe((float) $listing->fresh()->price);
    });

    test('products customers cannot see are left out', function () {
        $visible = linkableProduct();
        linkableProduct(['status' => 'draft']);
        linkableProduct(['show_online' => false]);
        linkProduct(linkVendor('No Photo')); // published, but no photo

        expect(collect(app(ProductLinkCatalogue::class)->forBrowser())->pluck('id')->all())->toBe([$visible->id]);
    });

    test('it is sorted by the name shown', function () {
        linkableProduct(['online_name' => 'zebra case']);
        linkableProduct(['online_name' => 'Apple charger']);
        linkableProduct(['online_name' => 'mango power bank']);

        expect(collect(app(ProductLinkCatalogue::class)->forBrowser())->pluck('displayName')->all())
            ->toBe(['Apple charger', 'mango power bank', 'zebra case']);
    });

    test('it is built without a query per product', function () {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ProductLinkCatalogue::class)->forBrowser();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        linkableProduct();
        resoldListing('First Resold');
        $few = $countQueries();

        foreach (range(1, 4) as $i) {
            linkableProduct();
            resoldListing("More Resold {$i}");
        }

        expect(count(app(ProductLinkCatalogue::class)->forBrowser()))->toBe(10)
            ->and($countQueries())->toBe($few);
    });
});

describe('the link and the QR', function () {
    test('the link the browser builds is exactly the one the server builds', function () {
        $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
        $product = linkableProduct();
        $qr = app(QrCodeService::class);

        // The page's JS: linkBase (the referral link) + '?to=' + encodeURIComponent(slug)
        $builtInBrowser = $qr->referralLinkUrl($affiliate).'?to='.rawurlencode($product->slug);

        expect($builtInBrowser)->toBe($qr->productLinkUrl($affiliate, $product))
            ->and($builtInBrowser)->toEndWith('/r/'.$affiliate->code.'?to='.$product->slug);
    });

    test('a product\'s QR is fetched on demand, for that product only', function () {
        $affiliate = signedInAffiliate();
        $product = linkableProduct();
        $expectedLink = app(QrCodeService::class)->productLinkUrl($affiliate, $product);

        $page = Volt::test('pages.account.affiliate');

        // Loading the page draws no product QR — only the referral one.
        expect($affiliate->fresh()->getMedia('qr-codes')->pluck('custom_properties.qr_key')->all())
            ->not->toContain("product-{$product->id}");

        $page->call('productQr', $product->id)
            ->assertReturned(fn ($result) => $result['link'] === $expectedLink
                && str_contains($result['svg'], '<svg'));

        expect($affiliate->fresh()->getMedia('qr-codes')->pluck('custom_properties.qr_key')->all())
            ->toContain("product-{$product->id}");
    });

    test('no QR for a product customers cannot see', function () {
        signedInAffiliate();
        $hidden = linkableProduct(['status' => 'draft']);

        Volt::test('pages.account.affiliate')
            ->call('productQr', $hidden->id)
            ->assertReturned(null);
    });
});

describe('links handed out before a rename', function () {
    test('an old product slug still deep-links to the product, with the click and cookie as before', function () {
        $affiliate = Affiliate::findOrCreateForUser(User::factory()->create());
        $product = linkableProduct(['name' => 'ITEL 2160 OLD ONE']);
        $oldSlug = $product->slug;

        $product->update(['online_name' => 'itel 2160 Dual SIM Keypad Phone']);
        $newSlug = $product->fresh()->slug;
        expect($newSlug)->not->toBe($oldSlug);

        $this->get("/r/{$affiliate->code}?to={$oldSlug}")
            ->assertRedirect(route('product.show', $newSlug))
            ->assertCookie(AttributionService::COOKIE_NAME, $affiliate->code);

        expect(AffiliateClick::where('affiliate_id', $affiliate->id)->count())->toBe(1);
    });
});

describe('the tabbed page', function () {
    test('all four tabs render on load, so switching never needs the server', function () {
        signedInAffiliate();

        Volt::test('pages.account.affiliate')
            ->assertSeeHtml('id="tab-home"')
            ->assertSeeHtml('id="tab-promote"')
            ->assertSeeHtml('id="tab-earn"')
            ->assertSeeHtml('id="tab-wallet"')
            ->assertSee('Product Links')
            ->assertSee('Plug Points')
            ->assertSee('Payout Bank Details');
    });

    test('a brand-new affiliate is greeted with their link, not a row of zeros', function () {
        $affiliate = signedInAffiliate();

        Volt::test('pages.account.affiliate')
            ->assertSee("Here's your link — go share it", false)
            ->assertSee(app(QrCodeService::class)->referralLinkUrl($affiliate))
            ->assertDontSee('Balances')
            ->assertSee('No visits yet');
    });

    test('once their link has been visited, Home shows their balances', function () {
        $affiliate = signedInAffiliate();
        AffiliateClick::create(['affiliate_id' => $affiliate->id, 'landing_url' => 'home']);

        Volt::test('pages.account.affiliate')
            ->assertSee('Balances')
            ->assertDontSee("Here's your link — go share it", false)
            ->assertDontSee('No visits yet');
    });
});
