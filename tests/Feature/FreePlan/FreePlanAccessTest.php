<?php

use App\Mail\FreeShopMail;
use App\Models\FreePlanSetting;
use App\Models\Vendor;
use Database\Factories\ProductFactory;
use App\Models\VendorStatusLog;
use App\Services\FreePlan\FreeShopApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/helpers.php';

// The panel's POS nav item calls hasPermissionTo('access_pos') while building
// the sidebar; seeded so a render is a real render, not a 500.
beforeEach(function () {
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();
    FreePlanSetting::current()->update(['admin_notification_email' => 'reviews@gadgetplug.test']);
});

function freeShopPanelUrl(Vendor $vendor, string $path = 'dashboard'): string
{
    return '/plug/'.$vendor->slug.'/'.$path;
}

// ── Pending ────────────────────────────────────────────────────────────────

it('shows a pending shop the review banner and its link, not yet live', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop();

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertOk()
        ->assertSee('Your shop is under review.')
        ->assertSee('Add your products now so your shop is ready')
        ->assertSee($vendor->shopLinkLabel().'</span> — not live yet', false);
});

it('lets a pending shop add products', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop();

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'products/create'))->assertOk();
    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'products'))->assertOk();
});

it('shows a free shop only Products and Shop Profile in the menu', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop();

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertOk()
        ->assertSee('Shop Profile')
        ->assertDontSee('Sales Point Terminal')
        ->assertDontSee('Store Profile');
});

it('refuses every other panel page to a free shop, typed in directly', function (string $path) {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, $path))
        ->assertRedirect(route('filament.vendor.pages.dashboard', ['tenant' => $vendor]));
})->with(['store-profile', 'reports-hub', 'sales-report', 'inventory-page', 'import-products', 'pos-sales', 'procurements', 'team-members']);

it('keeps a free shop out of the till and procurement', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $dashboard = route('filament.vendor.pages.dashboard', ['tenant' => $vendor]);

    $this->actingAs($owner)->get('/sales-point/'.$vendor->slug)->assertRedirect($dashboard);
    $this->actingAs($owner)->get('/procurement/create')->assertRedirect($dashboard);
});

it('refuses a free shop the till API', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    Sanctum::actingAs($owner);

    $this->getJson('/api/pos/products?vendor_id='.$vendor->id)
        ->assertForbidden()
        ->assertJson(['message' => Vendor::FREE_PLAN_NO_TILL]);
});

it('leaves a paid vendor exactly as before', function () {
    ['owner' => $owner, 'vendor' => $vendor] = paidShop();

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertOk()
        ->assertDontSee('Your shop is under review.')
        ->assertSee('Store Profile');

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'store-profile'))->assertOk();
    $this->actingAs($owner)->get('/sales-point/'.$vendor->slug)->assertOk();
    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'shop-profile'))->assertStatus(302);
});

// ── More info requested ────────────────────────────────────────────────────

it('shows the admin note and an update button when more info is requested', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_PENDING, [
        'info_requested' => true,
        'review_note'    => 'Please send a clearer photo of your shop front.',
    ]);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertOk()
        ->assertSee('Please send a clearer photo of your shop front.')
        ->assertSee('Update application')
        ->assertSee(route('free-shop.application.edit'), false);
});

it('clears the request and tells the admin when the shop answers it', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_PENDING, [
        'info_requested' => true,
        'review_note'    => 'Need a photo.',
        'slug'           => 'joy-answers',
    ]);

    $this->actingAs($owner)->get('/my-shop/application')->assertOk()->assertSee('Need a photo.');

    $this->actingAs($owner)->put('/my-shop/application', [
        'business_name'      => $vendor->name,
        'owner_name'         => 'Joy O.',
        'whatsapp'           => $vendor->whatsapp,
        'category'           => 'Accessories',
        'state'              => 'Lagos',
        'city_area'          => 'Yaba',
        'slug'               => 'joy-answers',
        'verification_photo' => UploadedFile::fake()->image('shop.jpg'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $vendor->refresh();
    expect($vendor->info_requested)->toBeFalse()
        ->and($vendor->status)->toBe('pending')
        ->and($vendor->category)->toBe('Accessories')
        ->and($vendor->city)->toBe('Yaba')
        ->and($owner->fresh()->name)->toBe('Joy O.')
        ->and(VendorStatusLog::where('vendor_id', $vendor->id)->latest('id')->first()->note)
            ->toBe('Application updated with the requested info.');

    Mail::assertQueued(FreeShopMail::class, fn ($m) => str_starts_with($m->subjectLine, 'Application updated'));
});

it('does not let a pending shop without a request edit its application', function () {
    ['owner' => $owner] = freeShop();

    $this->actingAs($owner)->get('/my-shop/application')->assertRedirect();
    $this->actingAs($owner)->put('/my-shop/application', [])->assertForbidden();
});

// ── Rejected ───────────────────────────────────────────────────────────────

it('sends a rejected shop to the resubmit form, with the reason', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_REJECTED, [
        'review_note' => 'We could not verify the business.',
    ]);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))->assertRedirect(route('free-shop.application.edit'));

    $this->actingAs($owner)->get('/my-shop/application')
        ->assertOk()
        ->assertSee('Why your application was not approved')
        ->assertSee('We could not verify the business.');
});

it('puts a resubmitted shop back to pending and logs it', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_REJECTED, [
        'review_note' => 'No proof.',
        'slug'        => 'joy-again',
    ]);

    $this->actingAs($owner)->put('/my-shop/application', [
        'business_name' => $vendor->name,
        'owner_name'    => $owner->name,
        'whatsapp'      => $vendor->whatsapp,
        'category'      => $vendor->category,
        'state'         => $vendor->state,
        'city_area'     => $vendor->city,
        'slug'          => 'joy-again-2',
        'social_link'   => 'https://www.facebook.com/joyagain',
    ])->assertSessionHasNoErrors();

    $vendor->refresh();
    $log = VendorStatusLog::where('vendor_id', $vendor->id)->latest('id')->first();

    expect($vendor->status)->toBe('pending')
        ->and($vendor->slug)->toBe('joy-again-2')
        ->and($log->from_status)->toBe('rejected')
        ->and($log->to_status)->toBe('pending');

    Mail::assertQueued(FreeShopMail::class, fn ($m) => str_starts_with($m->subjectLine, 'Application resubmitted'));
});

// ── Suspended ──────────────────────────────────────────────────────────────

it('falls back to a plain support line when no support number is set', function () {
    config(['app.support_whatsapp' => null]);
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_SUSPENDED);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertStatus(403)
        ->assertSee('Your shop has been suspended.')
        ->assertSee('Please contact')
        ->assertDontSee('wa.me', false);
});

it('blocks a suspended shop from everything but the suspended page', function () {
    config(['app.support_whatsapp' => '0813 631 0313']);
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_SUSPENDED);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertStatus(403)
        ->assertSee('Your shop has been suspended.')
        ->assertSee('0813 631 0313')
        ->assertSee('https://wa.me/2348136310313', false);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'products'))->assertStatus(403);
});

// ── Approved ───────────────────────────────────────────────────────────────

it('shows an approved shop its live link and no review banner', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor))
        ->assertOk()
        ->assertSee('Your shop link is live')
        ->assertDontSee('Your shop is under review.');
});

it('lets a free shop edit its profile but never its link', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'shop-profile'))
        ->assertOk()
        ->assertSee($vendor->shopLinkLabel())
        ->assertSee('Your shop link cannot be changed after approval.');
});

// ── Product limit ──────────────────────────────────────────────────────────

it('blocks the product past the free plan limit', function () {
    FreePlanSetting::current()->update(['free_plan_product_limit' => 2]);
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    ProductFactory::new()->count(2)->create(['vendor_id' => $vendor->id]);

    expect(fn () => ProductFactory::new()->create(['vendor_id' => $vendor->id]))
        ->toThrow(ValidationException::class, "You've reached the free plan limit of 2 products.");

    expect($vendor->products()->count())->toBe(2);
});

it('blocks the 51st product at the default limit', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_PENDING);

    ProductFactory::new()->count(50)->create(['vendor_id' => $vendor->id]);

    expect(fn () => ProductFactory::new()->create(['vendor_id' => $vendor->id]))
        ->toThrow(ValidationException::class, "You've reached the free plan limit of 50 products.");
});

it('turns a shop at its limit away from the create page', function () {
    FreePlanSetting::current()->update(['free_plan_product_limit' => 1]);
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    ProductFactory::new()->create(['vendor_id' => $vendor->id]);

    $this->actingAs($owner)->get(freeShopPanelUrl($vendor, 'products/create'))
        ->assertRedirect();
});

it('never limits a paid vendor', function () {
    FreePlanSetting::current()->update(['free_plan_product_limit' => 1]);
    ['vendor' => $vendor] = paidShop();

    ProductFactory::new()->count(3)->create(['vendor_id' => $vendor->id]);

    expect($vendor->products()->count())->toBe(3);
});

it('builds the WhatsApp messages the admin sends', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_REJECTED, ['review_note' => 'Blurry photo', 'name' => 'Joy Provisions', 'slug' => 'joy-provisions']);

    expect(FreeShopApplications::whatsAppMessage($vendor, 'approved'))
        ->toContain('great news! Your GadgetPlug shop Joy Provisions is now live: '.$vendor->shopLinkUrl())
        ->and(FreeShopApplications::whatsAppMessage($vendor, 'rejected'))
        ->toContain("wasn't approved. Reason: Blurry photo. You can log in, edit your details and resubmit.")
        ->and(FreeShopApplications::whatsAppLink($vendor))
        ->toStartWith('https://wa.me/'.$vendor->whatsapp.'?text=');
});
