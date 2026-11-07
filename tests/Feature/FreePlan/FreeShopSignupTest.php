<?php

use App\Mail\FreeShopMail;
use App\Models\FreePlanSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorStatusLog;
use App\Services\FreePlan\FreeShopApplications;
use App\Support\ShopSlug;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();
    RateLimiter::clear('free-shop-signup');
    FreePlanSetting::current()->update(['admin_notification_email' => 'reviews@gadgetplug.test']);
});

it('shows the signup form to a guest', function () {
    $html = $this->get('/become-a-vendor')
        ->assertOk()
        ->assertSee('List your business free')
        ->assertSee(Vendor::shopLinkPrefix())
        ->assertSee('Helps us verify your business. A photo of your shop, stall, or products works.')
        ->getContent();

    // The form's starting state reaches Alpine as one value with no bare
    // double quote in it, so the x-data attribute cannot be cut short.
    expect(preg_match('/x-data="freeShopForm\((JSON\.parse\(\'[^"]*\'\))\)"/', $html, $m))->toBe(1);
    expect(json_decode(json_decode('"'.substr($m[1], 12, -2).'"'), true))
        ->toMatchArray(['step' => 1, 'slugTouched' => false, 'checkUrl' => route('free-shop.check-slug')]);
});

it('creates a free pending shop, signs the owner in and tells the admin', function () {
    $response = $this->post('/become-a-vendor', signupPayload());

    $vendor = Vendor::where('slug', 'joy-provisions')->first();

    expect($vendor)->not->toBeNull()
        ->and($vendor->plan)->toBe('free')
        ->and($vendor->status)->toBe('pending')
        ->and($vendor->whatsapp)->toBe('2348031234567')
        ->and($vendor->city)->toBe('Ikeja')
        ->and($vendor->applied_at)->not->toBeNull()
        ->and($vendor->user->email)->toBe('joy@example.com')
        ->and($vendor->users()->whereKey($vendor->user_id)->exists())->toBeTrue()
        // The observer seeded a default store, as for an admin-made vendor.
        ->and($vendor->stores()->where('is_default', true)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($vendor->user);
    $response->assertRedirect(\Filament\Facades\Filament::getPanel('vendor')->getUrl());

    $log = VendorStatusLog::where('vendor_id', $vendor->id)->sole();
    expect($log->from_status)->toBeNull()->and($log->to_status)->toBe('pending')
        ->and($log->changed_by)->toBe($vendor->user_id);

    Mail::assertQueued(FreeShopMail::class, fn (FreeShopMail $m) => $m->hasTo('reviews@gadgetplug.test')
        && $m->subjectLine === 'New vendor application: Joy Provisions');
});

it('stores the verification photo privately, resized to a jpg', function () {
    $this->post('/become-a-vendor', signupPayload());

    $vendor = Vendor::where('slug', 'joy-provisions')->first();

    expect($vendor->verification_photo)->toEndWith('.jpg');
    Storage::disk('local')->assertExists($vendor->verification_photo);
    Storage::disk('public')->assertMissing($vendor->verification_photo);

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($vendor->verification_photo));
    expect(max($width, $height))->toBe(1600);
});

it('lets only a platform admin see the verification photo', function () {
    $this->post('/become-a-vendor', signupPayload());
    $vendor = Vendor::where('slug', 'joy-provisions')->first();
    $url = route('free-shop.verification-photo', ['vendor' => $vendor->id]);

    // The shop's own owner (still signed in from signup) cannot. The app's
    // 403 handler turns a refused GET into a redirect, so assert the refusal
    // rather than the status: no image comes back.
    $refused = $this->get($url);
    expect($refused->status())->not->toBe(200)
        ->and((string) $refused->headers->get('Content-Type'))->not->toContain('image');

    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($admin)->get($url)->assertOk();
});

it('normalises the WhatsApp number from every common shape', function (string $typed) {
    expect(WhatsAppNumber::normalise($typed))->toBe('2348031234567');
})->with(['08031234567', '0803 123 4567', '8031234567', '+2348031234567', '234 803 123 4567', '+234 0803 123 4567']);

it('refuses WhatsApp numbers that cannot be dialled', function (string $typed) {
    $this->post('/become-a-vendor', signupPayload(['whatsapp' => $typed]))
        ->assertSessionHasErrors('whatsapp');

    expect(Vendor::count())->toBe(0);
})->with(['12345', '0803123456', '080312345678', '05031234567', 'not a number']);

it('refuses a WhatsApp number another vendor already uses, in any format', function () {
    paidShop()['vendor']->update(['whatsapp' => '0803 123 4567']);

    $this->post('/become-a-vendor', signupPayload(['whatsapp' => '+2348031234567']))
        ->assertSessionHasErrors(['whatsapp' => 'This WhatsApp number is already used by another shop.']);
});

it('refuses reserved links and any first segment of an app route', function (string $slug) {
    $this->post('/become-a-vendor', signupPayload(['slug' => $slug]))
        ->assertSessionHasErrors('slug');
})->with(['admin', 'shop', 'become-a-vendor', 'privacy', 'plug', 'sales-point', 'receipt', 'livewire', 'checkout']);

it('reads route segments live, so a new page is protected the day it ships', function () {
    expect(ShopSlug::problem('brand-new-page'))->toBeNull();

    Illuminate\Support\Facades\Route::get('/brand-new-page', fn () => 'hi');

    expect(ShopSlug::problem('brand-new-page'))->toBe('That link is reserved. Please choose another.');
});

it('enforces the link format', function (string $slug, string $message) {
    expect(ShopSlug::problem($slug))->toContain($message);
})->with([
    ['ab', '3 to 40'],
    [str_repeat('a', 41), '3 to 40'],
    ['-joy', 'hyphens'],
    ['joy-', 'hyphens'],
    ['joy_shop', 'hyphens'],
    ['Joy', 'hyphens'],
]);

it('refuses a link an existing vendor already has', function () {
    $existing = paidShop()['vendor'];

    $this->post('/become-a-vendor', signupPayload(['slug' => $existing->slug]))
        ->assertSessionHasErrors(['slug' => 'That link is already taken.']);
});

it('answers the live link check', function () {
    paidShop()['vendor']->update(['slug' => 'taken-name']);

    $this->getJson('/become-a-vendor/check-link?slug=taken-name')
        ->assertOk()->assertJson(['available' => false, 'message' => 'That link is already taken.']);

    $this->getJson('/become-a-vendor/check-link?slug=Free-Name')
        ->assertOk()->assertJson(['available' => true, 'slug' => 'free-name']);

    $this->getJson('/become-a-vendor/check-link?slug=dashboard')
        ->assertJson(['available' => false]);
});

it('needs a shop photo or a social link, and the link must be a social page', function () {
    $this->post('/become-a-vendor', signupPayload(['verification_photo' => null]))
        ->assertSessionHasErrors(['verification_photo', 'social_link']);

    $this->post('/become-a-vendor', signupPayload(['verification_photo' => null, 'social_link' => 'https://example.com/me']))
        ->assertSessionHasErrors('social_link');

    $this->post('/become-a-vendor', signupPayload(['verification_photo' => null, 'social_link' => 'https://www.instagram.com/joyprovisions']))
        ->assertSessionHasNoErrors();

    expect(Vendor::where('slug', 'joy-provisions')->value('social_link'))->toBe('https://www.instagram.com/joyprovisions');
});

it('keeps what was typed when validation fails', function () {
    $this->from('/become-a-vendor')
        ->post('/become-a-vendor', signupPayload(['slug' => 'admin']))
        ->assertRedirect('/become-a-vendor')
        ->assertSessionHasInput('business_name', 'Joy Provisions')
        ->assertSessionHasInput('city_area', 'Ikeja');
});

it('creates nothing when the honeypot is filled', function () {
    $this->post('/become-a-vendor', signupPayload(['company_website' => 'http://spam.example']));

    expect(Vendor::count())->toBe(0)->and(User::count())->toBe(0);
    $this->assertGuest();
});

it('rejects an email that already has an account', function () {
    User::factory()->create(['email' => 'joy@example.com']);

    $this->post('/become-a-vendor', signupPayload())->assertSessionHasErrors('email');
});

it('lets a shop sign up without an email and sign in with its WhatsApp number', function () {
    $this->post('/become-a-vendor', signupPayload(['email' => '']));

    $vendor = Vendor::where('slug', 'joy-provisions')->first();
    expect(FreeShopApplications::isPlaceholderEmail($vendor->user->email))->toBeTrue();

    auth()->logout();

    $this->post(route('login.store'), ['email' => '0803 123 4567', 'password' => 'Str0ng!Passw0rd'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($vendor->user);
});

it('still signs people in by email exactly as before', function () {
    $user = User::factory()->create(['email' => 'old@example.com', 'password' => 'Str0ng!Passw0rd']);

    $this->post(route('login.store'), ['email' => 'OLD@example.com', 'password' => 'Str0ng!Passw0rd']);
    $this->assertAuthenticatedAs($user);
});

it('does not sign a customer in by phone number', function () {
    User::factory()->create(['phone' => '2348031234567', 'password' => 'Str0ng!Passw0rd']);

    $this->post(route('login.store'), ['email' => '08031234567', 'password' => 'Str0ng!Passw0rd'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('never mails the placeholder address', function () {
    ['vendor' => $vendor] = freeShop();
    $vendor->user->update(['email' => FreeShopApplications::placeholderEmailFor($vendor->user)]);

    $admin = User::factory()->create();
    app(FreeShopApplications::class)->approve($vendor, $admin);

    Mail::assertNotQueued(FreeShopMail::class);
});

it('still signs the shop up when the mail cannot be sent', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

    $this->post('/become-a-vendor', signupPayload())->assertRedirect();

    expect(Vendor::where('slug', 'joy-provisions')->exists())->toBeTrue();
});

it('throttles signups to five an hour per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/become-a-vendor', signupPayload(['slug' => 'admin']));
    }

    $this->post('/become-a-vendor', signupPayload())->assertStatus(429);
});

it('keeps the link check on its own count, apart from the signup limit', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/become-a-vendor/check-link?slug=joy-'.$i)->assertOk();
    }

    $this->post('/become-a-vendor', signupPayload())->assertRedirect();
    expect(Vendor::where('slug', 'joy-provisions')->exists())->toBeTrue();
});

it('gives every vendor made the old way plan paid and status approved', function () {
    $vendor = paidShop()['vendor'];

    expect($vendor->plan)->toBe('paid')
        ->and($vendor->status)->toBe('approved')
        ->and($vendor->isFreePlan())->toBeFalse();
});

it('sends a signed-in shop owner to their dashboard instead of the form', function () {
    ['owner' => $owner] = freeShop();

    $this->actingAs($owner)->get('/become-a-vendor')->assertRedirect();
});
