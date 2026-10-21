<?php

use App\Models\Affiliate;
use App\Models\Category;
use App\Models\Product;
use Database\Factories\ProductFactory;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Volt\Volt;

function makeAffiliatePageProduct(): Product
{
    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Affiliate Page Store ' . uniqid(), 'online_sales_enabled' => true]);
    $category = Category::create(['name' => 'Affiliate Page Category ' . uniqid()]);

    return ProductFactory::new()->withPhoto()->create([
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

test('the page ships the linkable products once, for the in-browser search', function () {
    $user = User::factory()->create();
    Affiliate::findOrCreateForUser($user);
    $this->actingAs($user);

    $product = makeAffiliatePageProduct();

    Volt::test('pages.account.affiliate')
        ->assertSee('Affiliate Page Widget')
        ->assertSee($product->slug)
        // A later re-render (cancelling a task, a page of commissions) doesn't
        // resend the catalogue — the search sits under wire:ignore.
        ->call('cancelTaskSubmission')
        ->assertDontSee($product->slug);
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
