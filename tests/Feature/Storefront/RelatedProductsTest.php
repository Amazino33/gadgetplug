<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function relatedVendor(string $name): Vendor
{
    return Vendor::create([
        'user_id'              => User::factory()->create()->id,
        'name'                 => $name,
        'online_sales_enabled' => true,
    ]);
}

function relatedProduct(Vendor $vendor, Category $category, string $name, int $price, array $attrs = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => $name,
        'price'          => $price,
        'stock_quantity' => 5,
        'status'         => 'published',
        'show_online'    => true,
    ], $attrs));
}

test('the vendor rail shows the shop\'s other products in the same category', function () {
    $vendor   = relatedVendor('Power Plug');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($vendor, $category, '20000mAh Itel Power Bank', 20000);
    relatedProduct($vendor, $category, '30000mAh Itel Power Bank', 30000);

    Volt::test('pages.product-detail', ['product' => $viewing])
        ->assertSee('More from Power Plug')
        ->assertSee('30000mAh Itel Power Bank');
});

test('the product being viewed never appears in its own rail', function () {
    $vendor   = relatedVendor('Power Plug');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($vendor, $category, 'The One Being Viewed', 20000);
    relatedProduct($vendor, $category, 'A Different One', 30000);

    $component = Volt::test('pages.product-detail', ['product' => $viewing]);

    expect($component->instance()->relatedFromVendor->pluck('id'))
        ->not->toContain($viewing->id);
});

test('dearer products come first, so the rail opens on the upsell', function () {
    $vendor   = relatedVendor('Power Plug');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($vendor, $category, '20000mAh', 20000);
    $cheaper = relatedProduct($vendor, $category, '10000mAh', 10000);
    $nextUp  = relatedProduct($vendor, $category, '30000mAh', 28000);
    $biggest = relatedProduct($vendor, $category, '40000mAh', 41000);

    $ids = Volt::test('pages.product-detail', ['product' => $viewing])
        ->instance()->relatedFromVendor->pluck('id')->all();

    // Nearest step up first, then the bigger upgrade, and only then the
    // cheaper peer — an upsell order, not a price list.
    expect($ids)->toBe([$nextUp->id, $biggest->id, $cheaper->id]);
});

test('other vendors are kept in their own rail, never blended into the shop\'s', function () {
    $mine     = relatedVendor('Power Plug');
    $theirs   = relatedVendor('Gadget Bay');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($mine, $category, 'Mine 20000mAh', 20000);
    $ours    = relatedProduct($mine, $category, 'Mine 30000mAh', 30000);
    $others  = relatedProduct($theirs, $category, 'Theirs 26800mAh', 24000);

    $component = Volt::test('pages.product-detail', ['product' => $viewing]);

    expect($component->instance()->relatedFromVendor->pluck('id')->all())->toBe([$ours->id])
        ->and($component->instance()->relatedElsewhere->pluck('id')->all())->toBe([$others->id]);

    $component->assertSee('Gadget Bay');
});

test('a different category is not pulled in', function () {
    $vendor     = relatedVendor('Power Plug');
    $powerBanks = Category::create(['name' => 'Power Bank']);
    $phones     = Category::create(['name' => 'Phone']);

    $viewing = relatedProduct($vendor, $powerBanks, '20000mAh Power Bank', 20000);
    relatedProduct($vendor, $phones, 'Some Phone', 90000);

    $component = Volt::test('pages.product-detail', ['product' => $viewing]);

    expect($component->instance()->relatedFromVendor)->toBeEmpty()
        ->and($component->instance()->relatedElsewhere)->toBeEmpty();
});

test('out of stock and unpublished products stay out of the rails', function () {
    $vendor   = relatedVendor('Power Plug');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($vendor, $category, '20000mAh', 20000);
    relatedProduct($vendor, $category, 'Sold Out 30000mAh', 30000, ['stock_quantity' => 0]);
    relatedProduct($vendor, $category, 'Draft 40000mAh', 40000, ['status' => 'draft']);
    relatedProduct($vendor, $category, 'Hidden 50000mAh', 50000, ['show_online' => false]);

    expect(Volt::test('pages.product-detail', ['product' => $viewing])
        ->instance()->relatedFromVendor)->toBeEmpty();
});

test('a product with nothing else in its category simply shows no rail', function () {
    $vendor   = relatedVendor('Power Plug');
    $category = Category::create(['name' => 'Power Bank']);

    $viewing = relatedProduct($vendor, $category, 'The Only One', 20000);

    Volt::test('pages.product-detail', ['product' => $viewing])
        ->assertDontSee('More from Power Plug')
        ->assertDontSee('More Power Banks on GadgetPlug');
});

test('the category rail is rendered above the shop rail', function () {
    $mine     = relatedVendor('TechHaven');
    $theirs   = relatedVendor('Gadget Bay');
    $category = Category::create(['name' => 'Smartwatch']);

    $viewing = relatedProduct($mine, $category, 'Apple Watch Series 9', 200000);
    relatedProduct($mine, $category, 'Samsung Galaxy Watch 6', 180000);
    relatedProduct($theirs, $category, 'Xiaomi Smart Band 8', 45000);

    $html = Volt::test('pages.product-detail', ['product' => $viewing])->html();

    $categoryRail = strpos($html, 'More Smartwatches on GadgetPlug');
    $shopRail     = strpos($html, 'More from TechHaven');

    expect($categoryRail)->not->toBeFalse()
        ->and($shopRail)->not->toBeFalse()
        // Someone reading about a smartwatch wants the other smartwatches
        // first; what else this shop sells answers a later question.
        ->and($categoryRail)->toBeLessThan($shopRail);
});
