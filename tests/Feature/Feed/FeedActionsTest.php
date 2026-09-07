<?php

use App\Http\Middleware\EnsureDeviceToken;
use App\Listeners\ClaimGuestFeedActivity;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function actionVendor(bool $online = true): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Action Shop '.uniqid(),
        'online_sales_enabled' => $online,
    ]);
}

function actionProduct(?Vendor $vendor = null, array $over = []): Product
{
    $vendor ??= actionVendor();

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Action Cat'])->id,
        'name'           => 'Action Thing '.Str::random(5),
        'price'          => 9500,
        'stock_quantity' => 6,
        'status'         => 'published',
        'published_at'   => now(),
    ], $over));
}

/**
 * A request carrying a known device token.
 *
 * Built on call() with an explicit cookie array because withCookie() and
 * withCookies() deliver NOTHING in this suite — verified by dumping the request
 * cookie bag, which came back empty for both while call() carried the value
 * through. Without a cookie the middleware mints a fresh token per request, so
 * two calls that should share a device end up as two devices and nothing ever
 * toggles.
 */
function asDevice(string $token, string $method, string $url, array $data = [])
{
    return test()
        // EncryptCookies would try to decrypt this plain value, fail, and drop
        // it — after which the middleware mints a fresh token and nothing
        // toggles. Production is unaffected: there the framework encrypts on
        // the way out and decrypts on the way in.
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->call($method, $url, $data, [EnsureDeviceToken::COOKIE => $token], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
}

describe('liking', function () {
    test('a guest like persists against their device and comes back counted', function () {
        $product = actionProduct();

        $response = asDevice((string) Str::uuid(), 'POST', route('feed.like', $product))->assertOk();

        expect($response->json('liked'))->toBeTrue()
            ->and($response->json('like_count'))->toBe(1)
            ->and($product->fresh()->like_count)->toBe(1);
    });

    test('tapping again unlikes it', function () {
        $product = actionProduct();
        $device = (string) Str::uuid();

        asDevice($device, 'POST', route('feed.like', $product));
        $second = asDevice($device, 'POST', route('feed.like', $product))->assertOk();

        expect($second->json('liked'))->toBeFalse()
            ->and($product->fresh()->like_count)->toBe(0);
    });

    test('a guest like follows them into their account when they sign in', function () {
        $product = actionProduct();
        $device = (string) Str::uuid();

        asDevice($device, 'POST', route('feed.like', $product));

        $user = User::factory()->create();

        // The listener runs on the real login event, not on a helper.
        asDevice($device, 'POST', route('login.store'), [
            'email' => $user->email, 'password' => 'password',
        ]);

        $row = ProductInteraction::likes()->where('product_id', $product->id)->firstOrFail();

        expect($row->user_id)->toBe($user->id)
            ->and($row->device_token)->toBeNull();
    });

    test('a product that is no longer on the marketplace cannot be liked', function () {
        $product = actionProduct(actionVendor(online: false));

        asDevice((string) Str::uuid(), 'POST', route('feed.like', $product))->assertNotFound();

        expect(ProductInteraction::count())->toBe(0);
    });
});

describe('saving', function () {
    test('a guest is sent to log in, and the intent is kept', function () {
        $product = actionProduct();

        $this->postJson(route('feed.save', $product))
            ->assertStatus(401)
            ->assertJson(['requires_login' => true]);

        expect(session(ClaimGuestFeedActivity::PENDING_SAVE))
            ->toBe(['action' => 'save', 'product_id' => $product->id])
            ->and(Wishlist::count())->toBe(0);
    });

    test('the save completes by itself once they have signed in', function () {
        $product = actionProduct();
        $user = User::factory()->create();

        // Tapped Save as a guest...
        $this->postJson(route('feed.save', $product))->assertStatus(401);

        // ...then signed in. The gate should be a step, not a dead end.
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        expect(Wishlist::where('user_id', $user->id)->where('product_id', $product->id)->exists())
            ->toBeTrue();
    });

    test('a signed-in save writes the existing wishlist, and toggles off again', function () {
        $product = actionProduct();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('feed.save', $product))
            ->assertOk()->assertJson(['saved' => true]);

        expect(Wishlist::where('user_id', $user->id)->count())->toBe(1);

        $this->actingAs($user)->postJson(route('feed.save', $product))
            ->assertOk()->assertJson(['saved' => false]);

        expect(Wishlist::where('user_id', $user->id)->count())->toBe(0);
    });
});

describe('sharing', function () {
    test('a share is logged, counted, and hands back the deep link', function () {
        $product = actionProduct();

        $response = asDevice((string) Str::uuid(), 'POST', route('feed.share', $product))->assertOk();

        expect($response->json('share_count'))->toBe(1)
            // The link the OG tags already render a preview for.
            ->and($response->json('url'))->toBe(route('product.show', $product->slug))
            ->and(ProductInteraction::shares()->count())->toBe(1);
    });

    test('sharing the same product twice is two shares, not one', function () {
        $product = actionProduct();
        $device = (string) Str::uuid();

        asDevice($device, 'POST', route('feed.share', $product));
        $second = asDevice($device, 'POST', route('feed.share', $product))->assertOk();

        // A share is an event. Nothing about it is a toggle.
        expect($second->json('share_count'))->toBe(2)
            ->and(ProductInteraction::shares()->count())->toBe(2);
    });
});

describe('buy now', function () {
    test('it puts the product in the cart and goes to checkout', function () {
        $product = actionProduct();

        $this->post(route('feed.buy', $product))
            ->assertRedirect(route('checkout'));

        expect(Session::get('cart'))->toHaveKey($product->id)
            ->and(Session::get('cart')[$product->id]['quantity'])->toBe(1);
    });

    test('a product that sold out since the feed rendered lands on its page, not an empty checkout', function () {
        $product = actionProduct(null, ['stock_quantity' => 0]);

        $this->post(route('feed.buy', $product))
            ->assertRedirect(route('product.show', $product->slug));

        expect(Session::get('cart', []))->toBeEmpty();
    });

    test('a hidden vendor product cannot be bought from the feed', function () {
        $product = actionProduct(actionVendor(online: false));

        $this->post(route('feed.buy', $product))->assertRedirect(route('home'));

        expect(Session::get('cart', []))->toBeEmpty();
    });
});
