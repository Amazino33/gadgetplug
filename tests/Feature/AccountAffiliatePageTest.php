<?php

use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Volt\Volt;

function makeAffiliatePageProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Affiliate Page Store ' . uniqid()]);
    $category = Category::create(['name' => 'Affiliate Page Category ' . uniqid()]);

    return Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Affiliate Page Widget',
        'price'          => 2500,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);
}

test('a non-affiliate user sees the not-registered message', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('pages.account.affiliate')
        ->assertSee("You're not registered as an affiliate yet", false);
});

test('an affiliate sees their referral link and QR code', function () {
    $user = User::factory()->create();
    $affiliate = Affiliate::findOrCreateForUser($user);
    $this->actingAs($user);

    Volt::test('pages.account.affiliate')
        ->assertSee($affiliate->code)
        ->assertSee('<svg', false);
});

test('searching for a product and selecting it reveals a product-specific link and QR', function () {
    $user = User::factory()->create();
    Affiliate::findOrCreateForUser($user);
    $this->actingAs($user);

    $product = makeAffiliatePageProduct();

    Volt::test('pages.account.affiliate')
        ->set('productSearch', 'Affiliate Page Widget')
        ->assertSee('Affiliate Page Widget')
        ->call('selectProduct', $product->id)
        ->assertSee($product->slug);
});

test('the affiliate nav tab only appears for users who have an affiliate profile', function () {
    $nonAffiliate = User::factory()->create();
    $this->actingAs($nonAffiliate);
    $this->get('/account')->assertSee('Become an Affiliate')->assertDontSee('Become a Plug');

    $affiliateUser = User::factory()->create();
    Affiliate::findOrCreateForUser($affiliateUser);
    $this->actingAs($affiliateUser);
    $this->get('/account')->assertDontSee('Become an Affiliate');
});
