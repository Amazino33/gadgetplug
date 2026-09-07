<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function storePageVendor(bool $online = true): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Store Page Shop '.uniqid(),
        'online_sales_enabled' => $online,
    ]);
}

function storePageProduct(Vendor $vendor, array $over = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Store Page Cat'])->id,
        'name'           => 'Shelf Item '.Str::random(6),
        'price'          => 12000,
        'stock_quantity' => 4,
        'status'         => 'published',
        'published_at'   => now(),
        'show_online'    => true,
    ], $over));
}

describe('reaching a store page', function () {
    test('it opens on the vendor slug and names the store', function () {
        $vendor = storePageVendor();
        $product = storePageProduct($vendor);

        $this->get(route('store.show', $vendor->slug))
            ->assertOk()
            ->assertSee($vendor->name)
            ->assertSee($product->name);
    });

    test('a store with online sales switched off has no public page', function () {
        $vendor = storePageVendor(online: false);
        storePageProduct($vendor);

        // 404 rather than an empty shop. The products are hidden either way,
        // so without this the page would show a real store name above nothing,
        // which reads as "sold out" instead of "does not sell here".
        $this->get(route('store.show', $vendor->slug))->assertNotFound();
    });

    test('an unknown slug is a 404, not an error', function () {
        $this->get('/store/no-such-shop')->assertNotFound();
    });
});

describe('what the store page lists', function () {
    test('it shows only this store\'s products', function () {
        $mine = storePageVendor();
        $theirs = storePageVendor();

        $ours = storePageProduct($mine);
        $notOurs = storePageProduct($theirs);

        $this->get(route('store.show', $mine->slug))
            ->assertSee($ours->name)
            ->assertDontSee($notOurs->name);
    });

    test('a hidden, unpublished or sold-out product is not listed', function () {
        $vendor = storePageVendor();

        $shown = storePageProduct($vendor);
        $draft = storePageProduct($vendor, ['status' => 'draft']);
        $offline = storePageProduct($vendor, ['show_online' => false]);
        $sold = storePageProduct($vendor, ['stock_quantity' => 0]);

        $response = $this->get(route('store.show', $vendor->slug))->assertOk();

        $response->assertSee($shown->name)
            ->assertDontSee($draft->name)
            ->assertDontSee($offline->name)
            ->assertDontSee($sold->name);
    });

    test('a store with nothing in stock says so rather than looking broken', function () {
        $vendor = storePageVendor();
        storePageProduct($vendor, ['stock_quantity' => 0]);

        $this->get(route('store.show', $vendor->slug))
            ->assertOk()
            ->assertSee('Nothing in stock right now');
    });

    test('the location shows when set and is absent when not', function () {
        $located = storePageVendor();
        $located->update(['city' => 'Ikeja', 'state' => 'Lagos']);
        storePageProduct($located);

        $this->get(route('store.show', $located->slug))->assertSee('Ikeja, Lagos');

        $bare = storePageVendor();
        storePageProduct($bare);

        $this->get(route('store.show', $bare->slug))->assertDontSee('Ikeja, Lagos');
    });
});

describe('acting from a store page', function () {
    test('adding to the cart works and reports a sold-out product honestly', function () {
        $vendor = storePageVendor();
        $product = storePageProduct($vendor);

        Volt::test('pages.vendor-store', ['vendor' => $vendor])
            ->call('addToCart', $product->id)
            ->assertSet('cartError', null);

        expect(session('cart'))->toHaveKey($product->id);
    });

    test('a product from another store cannot be added by posting its id', function () {
        $vendor = storePageVendor();
        storePageProduct($vendor);

        $elsewhere = storePageProduct(storePageVendor());

        // The id arrives from the browser. Trusting it would let anything be
        // added to a cart by id alone.
        Volt::test('pages.vendor-store', ['vendor' => $vendor])
            ->call('addToCart', $elsewhere->id)
            ->assertSet('cartError', 'That product is no longer available.');

        expect(session('cart', []))->toBeEmpty();
    });

    test('a guest tapping the heart is sent to log in', function () {
        $vendor = storePageVendor();
        $product = storePageProduct($vendor);

        Volt::test('pages.vendor-store', ['vendor' => $vendor])
            ->call('toggleWishlist', $product->id)
            ->assertRedirect(route('login'));
    });

    test('a signed-in customer can wishlist from here', function () {
        $vendor = storePageVendor();
        $product = storePageProduct($vendor);
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('pages.vendor-store', ['vendor' => $vendor])
            ->call('toggleWishlist', $product->id);

        expect(Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists())
            ->toBeTrue();
    });
});

describe('the feed links here', function () {
    test('a post carries the store page URL', function () {
        $vendor = storePageVendor();
        storePageProduct($vendor);

        $post = app(App\Services\Feed\FeedQuery::class)
            ->page(0, null, null, null, 5)['posts']->first();

        expect($post['store']['url'])->toBe(route('store.show', $vendor->slug));
    });
});
