<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The suggestion list is cached for every storefront page; each test builds
    // its own catalogue and must be judged on that one.
    Cache::forget('storefront.search-suggestions');
});

function searchableProduct(string $name, array $attrs = [], ?Category $category = null): Product
{
    $vendor = Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => 'Search Store ' . uniqid(),
        'online_sales_enabled' => true,
    ]);

    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => ($category ?? Category::create(['name' => 'Search Category ' . uniqid()]))->id,
        'name'           => $name,
        'price'          => 25000,
        'stock_quantity' => 5,
        'status'         => 'published',
        'show_online'    => true,
    ], $attrs));
}

// ── Typing ──────────────────────────────────────────────────────────────────

test('typing finds matching products without submitting anything', function () {
    searchableProduct('Baseus 65W GaN Charger');
    searchableProduct('Anker Power Bank 20000mAh');

    Volt::test('components.search-overlay')
        ->set('q', 'charger')
        ->assertSee('Baseus 65W GaN Charger')
        ->assertDontSee('Anker Power Bank 20000mAh');
});

test('a single letter is held back rather than returning the whole catalogue', function () {
    searchableProduct('Baseus 65W GaN Charger');

    Volt::test('components.search-overlay')
        ->set('q', 'c')
        ->assertSee('Keep typing')
        ->assertDontSee('Baseus 65W GaN Charger');
});

test('the brand is searchable as well as the name', function () {
    searchableProduct('65W GaN Fast Adapter', ['brand' => 'Baseus']);

    Volt::test('components.search-overlay')
        ->set('q', 'baseus')
        ->assertSee('65W GaN Fast Adapter');
});

test('a name that starts with the term is ranked above one that merely contains it', function () {
    $contains = searchableProduct('Car Adapter For Charger Stand');
    $starts   = searchableProduct('Charger Cable 2m');

    $ids = Volt::test('components.search-overlay')
        ->set('q', 'charger')
        ->instance()->results->pluck('id')->all();

    expect($ids)->toBe([$starts->id, $contains->id]);
});

test('nothing found says so instead of showing an empty list', function () {
    searchableProduct('Baseus 65W GaN Charger');

    Volt::test('components.search-overlay')
        ->set('q', 'refrigerator')
        ->assertSee('Nothing for')
        ->assertSee('Browse everything');
});

test('a wildcard typed into the field is treated as text, not as a pattern', function () {
    searchableProduct('Baseus 65W GaN Charger');

    // Unescaped, '%' would match every product in the catalogue.
    Volt::test('components.search-overlay')
        ->set('q', '%%')
        ->assertSee('Nothing for')
        ->assertDontSee('Baseus 65W GaN Charger');
});

test('out of stock and hidden products never surface in search', function () {
    searchableProduct('Charger In Stock');
    searchableProduct('Charger Sold Out', ['stock_quantity' => 0]);
    searchableProduct('Charger Unpublished', ['status' => 'draft']);
    searchableProduct('Charger Hidden Online', ['show_online' => false]);

    Volt::test('components.search-overlay')
        ->set('q', 'charger')
        ->assertSee('Charger In Stock')
        ->assertDontSee('Charger Sold Out')
        ->assertDontSee('Charger Unpublished')
        ->assertDontSee('Charger Hidden Online');
});

test('clearing puts the suggestions back', function () {
    searchableProduct('Baseus 65W GaN Charger');

    Volt::test('components.search-overlay')
        ->set('q', 'charger')
        ->call('clear')
        ->assertSet('q', '')
        ->assertSee('Popular right now');
});

// ── The rotating hint ───────────────────────────────────────────────────────

test('only terms the catalogue can actually answer are suggested', function () {
    searchableProduct('Baseus 65W GaN Charger');

    $terms = Volt::test('components.search-overlay')->instance()->suggestions;

    expect($terms)->toContain('charger')
        // Nothing here is a laptop, so offering the word would lead straight
        // to "Nothing for laptop".
        ->and($terms)->not->toContain('laptop');
});

test('a term is suggested when only the category names it', function () {
    $watches = Category::create(['name' => 'Smartwatches & Wearables']);

    // Nothing is called a "smartwatch" — it is called an Apple Watch. The
    // shelf is what makes the word searchable.
    searchableProduct('Apple Watch Series 9', [], $watches);

    expect(Volt::test('components.search-overlay')->instance()->suggestions)
        ->toContain('smartwatch');
});

test('spacing and plurals do not decide whether a term is offered', function () {
    $banks = Category::create(['name' => 'Power Banks']);
    searchableProduct('Anker 20000mAh', [], $banks);

    expect(Volt::test('components.search-overlay')->instance()->suggestions)
        ->toContain('power bank');
});

test('an empty catalogue falls back to the shelves rather than an empty field', function () {
    $category = Category::create(['name' => 'Drones']);
    searchableProduct('DJI Mini 4 Pro', [], $category);

    // "Drones" matches none of the candidate words, so the shelf itself is
    // offered instead of nothing.
    expect(Volt::test('components.search-overlay')->instance()->suggestions)
        ->toBe(['Drones']);
});
