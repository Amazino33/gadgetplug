<?php

use App\Filament\Resources\FreeShopApplications\Pages\ViewFreeShopApplication;
use App\Filament\Vendor\Pages\ShopProfile;
use App\Mail\FreeShopMail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorStatusLog;
use App\Services\FreePlan\FreeShopApplications;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    (new Database\Seeders\VendorPermissionsSeeder())->run();
    Storage::fake('local');
    Storage::fake('public');
    RateLimiter::clear('free-shop-signup');
});

function freePlanAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

/** Signs a free shop's owner into their own panel, for Livewire tests of its pages. */
function actingAsShopOwner(Vendor $vendor): void
{
    test()->actingAs($vendor->user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

// ── Placeholder emails ─────────────────────────────────────────────────────

it('names the placeholder after the user id, on a domain that cannot resolve', function () {
    Mail::fake();

    $this->post('/become-a-vendor', signupPayload(['email' => '']));

    $user = Vendor::where('slug', 'joy-provisions')->first()->user;

    expect($user->email)->toBe($user->id.'@noemail.gadgetplug.invalid')
        ->and(FreeShopApplications::PLACEHOLDER_EMAIL_DOMAIN)->toEndWith('.invalid')
        ->and(FreeShopApplications::isPlaceholderEmail($user->email))->toBeTrue()
        ->and(FreeShopApplications::isPlaceholderEmail('joy@example.com'))->toBeFalse();
});

it('never sends any mail to a placeholder, whatever sends it', function () {
    config(['mail.default' => 'array']);
    ['vendor' => $vendor] = freeShop();
    $placeholder = FreeShopApplications::placeholderEmailFor($vendor->user);

    // A FreeShopMail addressed straight at it, past every caller's own check.
    Mail::to($placeholder)->sendNow(new FreeShopMail('Subject', 'Heading', ['line']));

    // A framework notification too: the password-reset link.
    $vendor->user->update(['email' => $placeholder]);
    PasswordBroker::sendResetLink(['email' => $placeholder]);

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('still delivers to the real recipients of a message that also names a placeholder', function () {
    config(['mail.default' => 'array']);
    ['vendor' => $vendor] = freeShop();

    Mail::to([FreeShopApplications::placeholderEmailFor($vendor->user), 'reviews@gadgetplug.test'])
        ->sendNow(new FreeShopMail('Subject', 'Heading', ['line']));

    $sent = app('mailer')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and(collect($sent->first()->getOriginalMessage()->getTo())->map->getAddress()->all())
        ->toBe(['reviews@gadgetplug.test']);
});

it('replaces the placeholder with a real email added in Shop Profile', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $vendor->user->update(['email' => FreeShopApplications::placeholderEmailFor($vendor->user)]);
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->assertSet('data.email', null)
        ->set('data.email', 'Joy@Example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect($vendor->user->fresh()->email)->toBe('joy@example.com');
});

it('refuses an email that already belongs to another account', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $placeholder = FreeShopApplications::placeholderEmailFor($vendor->user);
    $vendor->user->update(['email' => $placeholder]);
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->set('data.email', 'taken@example.com')
        ->call('save')
        ->assertHasErrors(['data.email' => 'unique']);

    expect($vendor->user->fresh()->email)->toBe($placeholder);
});

it('puts the placeholder back when the email is cleared', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $vendor->user->update(['email' => 'joy@example.com']);
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->assertSet('data.email', 'joy@example.com')
        ->set('data.email', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($vendor->user->fresh()->email)->toBe(FreeShopApplications::placeholderEmailFor($vendor->user));
});

// ── Admin password reset ───────────────────────────────────────────────────

it('makes an 8-character temporary password that passes the password rules and is safe to send on WhatsApp', function () {
    foreach (range(1, 200) as $_) {
        $temp = FreeShopApplications::temporaryPassword();

        expect(strlen($temp))->toBe(8)
            ->and($temp)->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/\d/')->toMatch('/[@#%+=?!]/')
            ->and($temp)->not->toMatch('/[*_~`0O1lI]/');

        expect(validator(['p' => $temp], ['p' => Illuminate\Validation\Rules\Password::default()])->passes())->toBeTrue();
    }
});

it('lets the platform admin reset the password, keeping only the hash and logging it', function () {
    $admin = freePlanAdmin();
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $oldHash = $vendor->user->password;

    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])
        ->callAction('resetPassword');

    $owner = $vendor->user->fresh();
    expect($owner->password)->not->toBe($oldHash);

    // The notification's title carries the password, shown this once. Read
    // the way Filament's own assertNotified() reads sent notifications.
    $tray = new \Filament\Notifications\Livewire\Notifications;
    $tray->mount();
    $notification = $tray->notifications->last();

    preg_match('/^Temporary password: (\S{8})$/', (string) $notification?->getTitle(), $m);
    $temp = $m[1] ?? null;

    expect($temp)->not->toBeNull()
        ->and(Hash::check($temp, $owner->password))->toBeTrue()
        ->and($owner->password)->not->toContain($temp);

    $sendButton = collect($notification->getActions())->first(fn ($a) => $a->getName() === 'sendPassword');
    expect($sendButton->getUrl())->toBe(FreeShopApplications::passwordResetWhatsAppLink($vendor, $temp))
        ->and(urldecode($sendButton->getUrl()))->toContain(
            "Hi {$owner->name}, your new GadgetPlug login password is {$temp}. Log in with your WhatsApp number and change it under Shop Profile."
        )->toStartWith('https://wa.me/'.$vendor->whatsapp.'?text=');

    $log = VendorStatusLog::where('vendor_id', $vendor->id)->latest('id')->first();
    expect($log->note)->toBe('Password reset by admin.')
        ->and($log->changed_by)->toBe($admin->id)
        ->and($log->from_status)->toBe('approved')->and($log->to_status)->toBe('approved')
        ->and(VendorStatusLog::where('note', 'like', "%{$temp}%")->exists())->toBeFalse();
});

it('lets the owner sign in with the temporary password and their WhatsApp number', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);

    $temp = app(FreeShopApplications::class)->resetPassword($vendor, freePlanAdmin());

    $this->post(route('login.store'), ['email' => $vendor->whatsapp, 'password' => $temp])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($vendor->user);
});

it('does not offer the reset to anyone but the platform admin', function () {
    ['owner' => $owner, 'vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $oldHash = $owner->password;

    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The page itself refuses them, so the action can never be reached. (The
    // app's 403 handler turns a refused GET into a redirect elsewhere.)
    Livewire::test(ViewFreeShopApplication::class, ['record' => $vendor->id])->assertStatus(302);

    // And the service itself refuses a paid vendor outright.
    expect(app(FreeShopApplications::class)->resetPassword(paidShop()['vendor'], $owner))->toBeNull();

    expect($owner->fresh()->password)->toBe($oldHash);
});

// ── Owner changes their password ───────────────────────────────────────────

it('lets the owner change their password in Shop Profile', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $vendor->user->update(['password' => 'Old!Passw0rd']);
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->set('data.current_password', 'Old!Passw0rd')
        ->set('data.new_password', 'N3w!Passw0rd')
        ->set('data.new_password_confirmation', 'N3w!Passw0rd')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('data.new_password', null);

    expect(Hash::check('N3w!Passw0rd', $vendor->user->fresh()->password))->toBeTrue();
});

it('needs the current password, a strong new one, and a matching confirmation', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $vendor->user->update(['password' => 'Old!Passw0rd']);
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->set('data.current_password', 'wrong')
        ->set('data.new_password', 'N3w!Passw0rd')
        ->set('data.new_password_confirmation', 'N3w!Passw0rd')
        ->call('save')
        ->assertHasErrors(['data.current_password']);

    Livewire::test(ShopProfile::class)
        ->set('data.current_password', 'Old!Passw0rd')
        ->set('data.new_password', 'weakpass')
        ->set('data.new_password_confirmation', 'weakpass')
        ->call('save')
        ->assertHasErrors(['data.new_password']);

    Livewire::test(ShopProfile::class)
        ->set('data.current_password', 'Old!Passw0rd')
        ->set('data.new_password', 'N3w!Passw0rd')
        ->set('data.new_password_confirmation', 'Different!1')
        ->call('save')
        ->assertHasErrors(['data.new_password_confirmation']);

    expect(Hash::check('Old!Passw0rd', $vendor->user->fresh()->password))->toBeTrue();
});

it('saves the profile without touching the password when those fields are left blank', function () {
    ['vendor' => $vendor] = freeShop(Vendor::STATUS_APPROVED);
    $hash = $vendor->user->password;
    actingAsShopOwner($vendor);

    Livewire::test(ShopProfile::class)
        ->set('data.city', 'Surulere')
        ->call('save')
        ->assertHasNoErrors();

    expect($vendor->fresh()->city)->toBe('Surulere')
        ->and($vendor->user->fresh()->password)->toBe($hash);
});
