<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Support\CategoryIcon;

// Covers the storefront fixes that are easy to undo by accident: dead links,
// missing accessible names, emoji creeping back in, and images without
// dimensions.

function storefrontProduct(array $attributes = []): Product
{
    $owner = User::factory()->create();

    $vendor = Vendor::create([
        'user_id' => $owner->id,
        'name'    => 'Leisure Hub',
        'online_sales_enabled' => true,
    ]);

    $category = Category::firstOrCreate(['name' => 'Smartphones']);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Pixel 9 Pro',
        'price'          => 850000,
        'stock_quantity' => 5,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_online'    => true,
        'show_in_pos'    => true,
    ], $attributes));
}

it('renders the storefront without any emoji', function () {
    storefrontProduct();

    $html = $this->get('/')->assertOk()->getContent();

    $emoji = preg_match_all('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{1F1E6}-\x{1F1FF}]/u', $html, $matches);

    expect($emoji)->toBe(0, 'Found emoji in the storefront: '.implode(' ', $matches[0] ?? []));
});

it('has no links that go nowhere', function () {
    storefrontProduct();

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'href="#"'))->toBe(0);
});

it('gives the search a label and a real submit button', function () {
    storefrontProduct();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-label="Search products"')
        ->and($html)->toContain('aria-label="Search"')
        ->and($html)->toContain('type="submit"');
});

it('actually searches when the form is submitted', function () {
    storefrontProduct(['name' => 'Pixel 9 Pro']);
    storefrontProduct(['name' => 'Galaxy S25', 'slug' => 'galaxy-s25']);

    $html = $this->get('/?search=Pixel')->assertOk()->getContent();

    expect($html)->toContain('Pixel 9 Pro')
        ->and($html)->not->toContain('Galaxy S25');
});

it('offers a skip link and names the main landmark', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('gp-skip-link')
        ->and($html)->toContain('id="main-content"');
});

it('gives product images explicit dimensions and defers the ones below the fold', function () {
    // Four products so at least one falls outside the first row, which is the
    // only part that should load eagerly.
    // A real (tiny) PNG — Spatie generates conversions on upload and cannot do
    // that with arbitrary bytes.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

    foreach (range(1, 4) as $i) {
        $product = storefrontProduct(['name' => "Test Phone {$i}", 'slug' => "test-phone-{$i}"]);
        $product->addMediaFromString($png)
            ->usingFileName("phone-{$i}.png")
            ->toMediaCollection('product-images');
    }

    $html = $this->get('/')->assertOk()->getContent();

    // Matched on the alt text: the rendered src is a conversion URL, which does
    // not carry the collection name.
    preg_match_all('/<img[^>]*alt="Test Phone[^>]*>/', $html, $matches);
    $productImages = $matches[0];

    expect($productImages)->not->toBeEmpty();

    foreach ($productImages as $img) {
        expect($img)->toMatch('/width="\d+"/')
            ->and($img)->toMatch('/height="\d+"/');
    }

    // The fourth card onwards is below the fold and must defer.
    expect($html)->toContain('loading="lazy"');
});

it('maps category names to icons by keyword', function () {
    expect(CategoryIcon::for('Smartphones'))->toBe('phone')
        ->and(CategoryIcon::for('Audio & Headphones'))->toBe('headphones')
        ->and(CategoryIcon::for('Laptops & Computers'))->toBe('laptop')
        ->and(CategoryIcon::for('Gaming'))->toBe('gamepad')
        ->and(CategoryIcon::for('Cameras & Photography'))->toBe('camera')
        ->and(CategoryIcon::for('Accessories & Cables'))->toBe('cable')
        // Renamed or unknown categories must still get something sensible
        ->and(CategoryIcon::for('Peripherals'))->toBe('cable')
        ->and(CategoryIcon::for('Something Unheard Of'))->toBe('package')
        ->and(CategoryIcon::for(null))->toBe('package');
});

it('keeps the track-order page rendering', function () {
    // Regression guard: the page 500'd because the component had no single root
    // element, and the footer now links to it.
    $this->get('/track')->assertOk();
});
