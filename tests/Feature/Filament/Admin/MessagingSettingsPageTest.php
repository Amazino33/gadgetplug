<?php

use App\Filament\Pages\MessagingSettings;
use App\Models\PlatformMessagingSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeSuperAdmin(): User
{
    $user = User::factory()->create();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    setPermissionsTeamId(null);
    $user->assignRole('super_admin');

    return $user;
}

test('a non super admin cannot reach the platform WhatsApp settings page', function () {
    $this->actingAs(User::factory()->create());

    expect(MessagingSettings::canAccess())->toBeFalse();
});

test('a super admin can reach the platform WhatsApp settings page', function () {
    $this->actingAs(makeSuperAdmin());

    expect(MessagingSettings::canAccess())->toBeTrue();

    Livewire::test(MessagingSettings::class)->assertSuccessful();
});

test('saving stores the fallback number', function () {
    $this->actingAs(makeSuperAdmin());

    Livewire::test(MessagingSettings::class)
        ->fillForm(['fallback_storekeeper_whatsapp' => '08133334444'])
        ->call('save');

    expect(PlatformMessagingSetting::current()->fresh()->fallback_storekeeper_whatsapp)
        ->toBe('08133334444');
});

// A typo here is invisible until an order silently fails to be announced.
test('a malformed number is rejected rather than saved', function () {
    $this->actingAs(makeSuperAdmin());

    Livewire::test(MessagingSettings::class)
        ->fillForm(['fallback_storekeeper_whatsapp' => '12345'])
        ->call('save')
        ->assertNotified();

    expect(PlatformMessagingSetting::current()->fresh()->fallback_storekeeper_whatsapp)->toBeNull();
});

test('clearing the number stores null so the fallback switches off cleanly', function () {
    $this->actingAs(makeSuperAdmin());
    PlatformMessagingSetting::current()->update(['fallback_storekeeper_whatsapp' => '08133334444']);

    Livewire::test(MessagingSettings::class)
        ->fillForm(['fallback_storekeeper_whatsapp' => ''])
        ->call('save');

    expect(PlatformMessagingSetting::current()->fresh()->fallback_storekeeper_whatsapp)->toBeNull();
});

test('the page lists vendors that have no storekeeper number of their own', function () {
    $this->actingAs(makeSuperAdmin());

    $configured = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Configured Store']);
    VendorNotificationSetting::forVendor($configured)->update(['storekeeper_whatsapp' => '08099887766']);

    $bare = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Bare Store']);
    VendorNotificationSetting::forVendor($bare);

    Livewire::test(MessagingSettings::class)
        ->assertSee('Bare Store')
        ->assertDontSee('Configured Store');
});
