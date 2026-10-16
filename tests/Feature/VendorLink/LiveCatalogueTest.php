<?php

use App\Actions\VendorLink\PublishLinkedListingAction;
use App\Models\Product;
use App\Services\Feed\FeedQuery;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

// A resold listing shows its SUPPLIER's photos and description, live, the same
// way it already shows his price and stock. When his side stops resolving —
// link off, product unpublished or deleted — the listing is hidden, and never
// falls back to its own row or to photos copied onto it in the past.

/** Publish one supplier product; returns [listing, source, link]. */
function liveListing(bool $sourcePhoto = true, int $stock = 5): array
{
    $reseller = linkVendor('Reseller');
    $supplier = linkVendor('Wholesaler', online: false);
    $link = makeLink($reseller, $supplier, markup: 40);
    $source = linkProduct($supplier, price: 10000, stock: $stock);
    $source->update(['description' => 'Dual SIM keypad phone, 1000mAh battery.']);

    if ($sourcePhoto) {
        ProductFactory::attachPhoto($source);
    }

    app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

    return [Product::linked()->firstOrFail(), $source->fresh(), $link];
}

function isVisible(Product $product): bool
{
    return Product::visibleOnline()->whereKey($product->id)->exists();
}

describe('photos come from the supplier, live', function () {
    test('a listing with no photo of its own is visible because its supplier has one', function () {
        [$listing, $source] = liveListing();

        expect($listing->getMedia('product-images'))->toHaveCount(0)
            ->and(isVisible($listing))->toBeTrue()
            ->and($listing->catalogueImageUrl())->toBe($source->getFirstMediaUrl('product-images'));

        $this->get(route('product.show', $listing->slug))
            ->assertOk()
            ->assertSee($source->getFirstMedia('product-images')->getUrl(), false);
    });

    test('a supplier changing his photo changes the listing immediately', function () {
        [$listing, $source] = liveListing();
        $old = $source->getFirstMedia('product-images');

        $new = ProductFactory::attachPhoto($source);
        Media::setNewOrder([$new->id, $old->id]);

        $fresh = Product::findOrFail($listing->id);

        expect($fresh->catalogueImageUrl())->toBe($new->getUrl())
            ->and($fresh->catalogueMedia()->pluck('id')->all())->toBe([$new->id, $old->id]);

        $this->get(route('product.show', $listing->slug))->assertSee($new->getUrl(), false);
    });

    test('a supplier with no photo hides every listing of his product', function () {
        [$listing] = liveListing(sourcePhoto: false);

        expect(isVisible($listing))->toBeFalse();
        $this->get(route('product.show', $listing->slug))->assertNotFound();
    });

    test('the description shown is his, and follows his edits', function () {
        [$listing, $source] = liveListing();

        $this->get(route('product.show', $listing->slug))->assertSee('Dual SIM keypad phone, 1000mAh battery.');

        $source->update(['description' => 'Now with a torch.']);

        $this->get(route('product.show', $listing->slug))
            ->assertSee('Now with a torch.')
            ->assertDontSee('1000mAh battery');
    });

    test('a feed post of a listing carries his photo and his description', function () {
        [$listing, $source] = liveListing();

        $post = collect(app(FeedQuery::class)->page(0, null, null, null, 50)['posts'])->firstWhere('id', $listing->id);

        expect($post)->not->toBeNull()
            ->and($post['caption'])->toBe('Dual SIM keypad phone, 1000mAh battery.')
            ->and($post['image']['src'])->toContain((string) $source->getFirstMedia('product-images')->id);
    });
});

describe('an unresolvable source hides the listing — never a fallback', function () {
    test('link switched off: hidden, even with an old copied photo on the listing', function () {
        [$listing, , $link] = liveListing();
        ProductFactory::attachPhoto($listing); // what the old publish used to copy

        $link->update(['is_active' => false]);
        $fresh = Product::findOrFail($listing->id);

        expect(isVisible($fresh))->toBeFalse()
            ->and($fresh->catalogueSource())->toBeNull()
            ->and($fresh->catalogueImageUrl())->toBe('');

        $this->get(route('product.show', $listing->slug))->assertNotFound();
    });

    test('source unpublished: hidden', function () {
        [$listing, $source] = liveListing();

        $source->update(['status' => 'draft']);

        expect(isVisible($listing))->toBeFalse()
            ->and(Product::findOrFail($listing->id)->catalogueSource())->toBeNull();
    });

    test('source deleted: the listing stays a hidden resold listing, not an ordinary product', function () {
        [$listing, $source] = liveListing();
        ProductFactory::attachPhoto($listing); // an old copy must not resurface

        $source->delete(); // products.source_product_id is ON DELETE SET NULL

        $orphan = Product::findOrFail($listing->id);

        expect($orphan->source_product_id)->toBeNull()
            ->and($orphan->isLinked())->toBeTrue()
            ->and($orphan->tracksStock())->toBeFalse()
            ->and($orphan->catalogueSource())->toBeNull()
            ->and(isVisible($orphan))->toBeFalse();

        $this->get(route('product.show', $orphan->slug))->assertNotFound();
    });
});

describe('a listing that stops resolving leaves the cart with a reason', function () {
    test('the cart page removes it and says so', function () {
        [$listing, , $link] = liveListing();
        $link->update(['is_active' => false]);

        $this->withSession(['cart' => [$listing->id => ['quantity' => 1, 'max' => 5]]])
            ->get(route('cart'))
            ->assertOk()
            ->assertSee('is no longer available and has been removed from your cart');

        expect(session('cart'))->not->toHaveKey($listing->id);
    });

    test('checkout drops it and keeps the rest of the cart', function () {
        Queue::fake();
        [$listing, , $link] = liveListing();
        $ordinary = ProductFactory::new()->withPhoto()->create(['vendor_id' => $listing->vendor_id]);
        $link->update(['is_active' => false]);

        $this->withSession(['cart' => [
            $listing->id  => ['quantity' => 1, 'max' => 5],
            $ordinary->id => ['quantity' => 1, 'max' => 5],
        ]])->get(route('checkout'));

        expect(session('cart'))->not->toHaveKey($listing->id)
            ->and(session('cart'))->toHaveKey($ordinary->id)
            ->and(session('error'))->toContain('no longer available');
    });
});

describe('publishing', function () {
    test('copies no photo and no description, first time or again', function () {
        [$listing, $source, $link] = liveListing();

        expect($listing->getMedia('product-images'))->toHaveCount(0)
            ->and($listing->description)->toBeNull();

        app(PublishLinkedListingAction::class)->execute($link, [$source->id]);

        expect($listing->fresh()->getMedia('product-images'))->toHaveCount(0)
            ->and($listing->fresh()->description)->toBeNull();
    });
});

describe('a page of listings resolves its supplier side up front', function () {
    test('with withCatalogue() eager-loaded, reading photos costs no further queries', function () {
        foreach (range(1, 3) as $i) {
            liveListing();
        }

        $listings = Product::linked()->withCatalogue()->get();

        DB::enableQueryLog();
        $listings->each(fn (Product $p) => $p->catalogueImageUrl('thumb'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($listings)->toHaveCount(3)
            ->and($queries)->toBe(0);
    });
});

describe('in the reseller\'s own panel', function () {
    test('a linked listing offers no photo upload, says where photos come from, and lists his photo', function () {
        (new \Database\Seeders\VendorPermissionsSeeder)->run();
        [$listing, $source] = liveListing();
        $reseller = $listing->vendor;

        test()->actingAs(\App\Models\User::find($reseller->user_id));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('vendor'));
        \Filament\Facades\Filament::setTenant($reseller);

        \Livewire\Livewire::test(\App\Filament\Vendor\Resources\Products\Pages\EditProduct::class, ['record' => $listing->getRouteKey()])
            ->assertOk()
            ->assertSee('Photos come from your supplier')
            ->assertFormFieldDoesNotExist('images');

        // Read through the gateway, so the panel's tenancy scope can't hide his row.
        expect(Product::findOrFail($listing->id)->catalogueImageUrl('thumb'))
            ->toBe($source->getFirstMediaUrl('product-images', 'thumb'));
    });
});

describe('vendorlink:purge-copied-media', function () {
    beforeEach(fn () => Storage::fake('public'));

    test('is a dry run by default and deletes nothing', function () {
        [$listing, $source] = liveListing();
        ProductFactory::attachPhoto($listing);

        $this->artisan('vendorlink:purge-copied-media')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        expect($listing->fresh()->getMedia('product-images'))->toHaveCount(1)
            ->and($source->fresh()->getMedia('product-images'))->toHaveCount(1);
    });

    test('with --force deletes only the copies on listings, never the supplier\'s photos', function () {
        [$listing, $source] = liveListing();
        ProductFactory::attachPhoto($listing);
        ProductFactory::attachPhoto($listing);

        $this->artisan('vendorlink:purge-copied-media', ['--force' => true])
            ->expectsOutputToContain('Deleted 2 copied photo(s)')
            ->assertSuccessful();

        expect($listing->fresh()->getMedia('product-images'))->toHaveCount(0)
            ->and($source->fresh()->getMedia('product-images'))->toHaveCount(1)
            ->and(isVisible($listing))->toBeTrue();
    });
});
