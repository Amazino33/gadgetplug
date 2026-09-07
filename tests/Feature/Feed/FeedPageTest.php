<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pageVendor(bool $online = true): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Feed Shop '.uniqid(),
        'online_sales_enabled' => $online,
    ]);
}

function pageProduct(Vendor $vendor, array $over = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Feed Page Cat'])->id,
        'name'           => 'Feed Thing '.Str::random(5),
        'price'          => 12500,
        'stock_quantity' => 4,
        'status'         => 'published',
        'published_at'   => now()->subMinutes(random_int(1, 500)),
    ], $over));
}

test('the homepage carries the mobile feed and the desktop grid side by side', function () {
    pageProduct(pageVendor());

    $this->get(route('home'))
        ->assertOk()
        // Mobile presentation.
        ->assertSee('lg:hidden', escape: false)
        // Desktop untouched, just gated by breakpoint.
        ->assertSee('hidden lg:block', escape: false)
        ->assertSee('Enter Store');
});

test('the first page of the feed is handed to the client with the page', function () {
    $product = pageProduct(pageVendor(), ['name' => 'Anker PowerCore']);

    // Rendered into the Alpine initial state, so the feed is populated before
    // a single request is made.
    $this->get(route('home'))->assertOk()->assertSee('Anker PowerCore');
});

test('a marketplace-off vendor never reaches the feed markup', function () {
    $hidden = pageProduct(pageVendor(online: false), ['name' => 'Hidden Widget']);
    $shown = pageProduct(pageVendor(), ['name' => 'Shown Widget']);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('Shown Widget')->assertDontSee('Hidden Widget');
});

test('the posts endpoint pages with a cursor', function () {
    // More than one page's worth (the endpoint serves 12), or there is
    // legitimately no next cursor to assert on.
    $vendors = collect(range(1, 3))->map(fn () => pageVendor());
    collect(range(1, 20))->each(fn ($i) => pageProduct($vendors[$i % 3]));

    $first = $this->getJson(route('feed.posts'))->assertOk();

    expect($first->json('posts'))->not->toBeEmpty()
        ->and($first->json('next_cursor'))->not->toBeNull();

    $second = $this->getJson(route('feed.posts', ['cursor' => $first->json('next_cursor')]))->assertOk();

    $firstIds = collect($first->json('posts'))->pluck('id');
    $secondIds = collect($second->json('posts'))->pluck('id');

    expect($firstIds->intersect($secondIds))->toBeEmpty();
});

test('the chips endpoint offers only categories with something buyable', function () {
    $vendor = pageVendor();

    $stocked = Category::create(['name' => 'Stocked '.uniqid(), 'slug' => 'stocked-'.uniqid()]);
    $empty = Category::create(['name' => 'Empty '.uniqid(), 'slug' => 'empty-'.uniqid()]);

    pageProduct($vendor, ['category_id' => $stocked->id]);
    pageProduct($vendor, ['category_id' => $empty->id, 'stock_quantity' => 0]);

    $names = collect($this->getJson(route('feed.categories'))->assertOk()->json('categories'))
        ->pluck('name');

    // A chip that opens an empty feed reads as the shop being broken.
    expect($names)->toContain($stocked->name)
        ->and($names)->not->toContain($empty->name);
});

test('a post carries only what it renders, not a whole product', function () {
    pageProduct(pageVendor());

    $post = $this->getJson(route('feed.posts'))->assertOk()->json('posts.0');

    expect(array_keys($post))->toEqualCanonicalizing([
        'id', 'name', 'slug', 'caption', 'price', 'url', 'image', 'store',
        'like_count', 'share_count', 'liked',
    ])
        // Never a broken image URL, even before conversions have run.
        ->and($post['image']['src'])->not->toBeEmpty();
});
