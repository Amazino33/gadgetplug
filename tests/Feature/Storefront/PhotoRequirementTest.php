<?php

use App\Models\Product;
use App\Models\Vendor;
use App\Services\Feed\FeedQuery;
use Database\Factories\ProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// A product with no photo is never shown to a customer (887f13f). That is a
// business decision, not a fixture inconvenience: other suites give their
// products a photo with ProductFactory::withPhoto() so they can test other
// things, and these tests make sure that convenience never hides the rule.
// Each pair is identical apart from the photo.

function photoRuleVendor(): Vendor
{
    return Vendor::factory()->onlineSalesEnabled()->create();
}

test('storefront: the catalogue lists a product with a photo and omits one without', function () {
    $vendor = photoRuleVendor();
    $shown = ProductFactory::new()->withPhoto()->create(['vendor_id' => $vendor->id, 'name' => 'Shown Phone With Photo']);
    $hidden = ProductFactory::new()->create(['vendor_id' => $vendor->id, 'name' => 'Hidden Phone No Photo']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee($shown->name)
        ->assertDontSee($hidden->name);

    expect(Product::visibleOnline()->pluck('id')->all())->toBe([$shown->id]);
});

test('storefront: the product page of a product without a photo is a 404', function () {
    $vendor = photoRuleVendor();
    $shown = ProductFactory::new()->withPhoto()->create(['vendor_id' => $vendor->id]);
    $hidden = ProductFactory::new()->create(['vendor_id' => $vendor->id]);

    $this->get(route('product.show', $shown->slug))->assertOk();
    $this->get(route('product.show', $hidden->slug))->assertNotFound();
});

test('feed: a product without a photo never becomes a post', function () {
    $vendor = photoRuleVendor();
    $shown = ProductFactory::new()->withPhoto()->create(['vendor_id' => $vendor->id]);
    $hidden = ProductFactory::new()->create(['vendor_id' => $vendor->id]);

    $ids = collect(app(FeedQuery::class)->page(0, null, null, null, 50)['posts'])->pluck('id')->all();

    expect($ids)->toContain($shown->id)
        ->and($ids)->not->toContain($hidden->id);
});

test('search: a product without a photo is never a search result', function () {
    Cache::forget('storefront.search-suggestions');
    $vendor = photoRuleVendor();
    ProductFactory::new()->withPhoto()->create(['vendor_id' => $vendor->id, 'name' => 'Oraimo Charger With Photo']);
    ProductFactory::new()->create(['vendor_id' => $vendor->id, 'name' => 'Oraimo Charger No Photo']);

    Volt::test('components.search-overlay')
        ->set('q', 'oraimo')
        ->assertSee('Oraimo Charger With Photo')
        ->assertDontSee('Oraimo Charger No Photo');
});
