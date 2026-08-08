<?php

use App\Filament\Vendor\Pages\NotificationSettings;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actAsSettingsVendor(): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Settings Store']);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('owner', 'vendor');
}

test('a storekeeper cannot access the WhatsApp alert settings page', function () {
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Storekeeper Visibility Store']);
    VendorRoles::seedFor($vendor);

    $storekeeper = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$storekeeper->id]);
    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole('storekeeper');

    $response = $this->actingAs($storekeeper)->get(
        route('filament.vendor.pages.notification-settings', ['tenant' => $vendor->slug])
    );

    $response->assertRedirect(
        route('filament.vendor.home', ['tenant' => $vendor->slug])
    );
});

test('a role explicitly granted manage_notification_settings can access the page', function () {
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Delegated Settings Store']);
    VendorRoles::seedFor($vendor);

    $manager = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$manager->id]);
    setPermissionsTeamId($vendor->id);
    $manager->assignRole('store_admin');

    \Spatie\Permission\Models\Role::where(['name' => 'store_admin', 'team_id' => $vendor->id])
        ->first()
        ->givePermissionTo('manage_notification_settings');

    $response = $this->actingAs($manager)->get(
        route('filament.vendor.pages.notification-settings', ['tenant' => $vendor->slug])
    );

    $response->assertOk();
});

test('the vendor owner can always access the WhatsApp alert settings page', function () {
    actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)->assertSuccessful();
});

test('the page renders with the migration defaults for a store that has never saved', function () {
    $data = actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)
        ->assertSuccessful()
        ->assertFormSet([
            'storekeeper_whatsapp' => null,
            'notify_new_order' => true,
            'notify_undispatched' => true,
            'notify_low_stock' => false,
            'notify_cancelled' => false,
            'undispatched_after_hours' => 6,
            'reminder_frequency' => '3h',
            'quiet_hours_enabled' => true,
        ]);

    // Opening the page is enough to materialise the row.
    expect(VendorNotificationSetting::where('vendor_id', $data['vendor']->id)->exists())->toBeTrue();
});

test('saving persists every setting', function () {
    $data = actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)
        ->fillForm([
            'storekeeper_whatsapp' => '08133334444',
            'notify_new_order' => true,
            'notify_undispatched' => true,
            'notify_low_stock' => true,
            'notify_cancelled' => true,
            'undispatched_after_hours' => 12,
            'reminder_frequency' => '6h',
            'quiet_hours_enabled' => true,
            'quiet_from' => '09:00',
            'quiet_until' => '18:00',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    expect($settings->storekeeper_whatsapp)->toBe('08133334444')
        ->and($settings->notify_low_stock)->toBeTrue()
        ->and($settings->notify_cancelled)->toBeTrue()
        ->and($settings->undispatched_after_hours)->toBe(12)
        ->and($settings->reminder_frequency)->toBe('6h')
        ->and($settings->quiet_hours_enabled)->toBeTrue();
});

test('clearing the number stores null rather than an empty string so alerts switch off cleanly', function () {
    $data = actAsSettingsVendor();
    VendorNotificationSetting::forVendor($data['vendor'])->update(['storekeeper_whatsapp' => '08133334444']);

    Livewire::test(NotificationSettings::class)
        ->fillForm(['storekeeper_whatsapp' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    expect($settings->storekeeper_whatsapp)->toBeNull()
        ->and($settings->hasStorekeeperNumber())->toBeFalse();
});

test('each vendor keeps its own settings', function () {
    $first = actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)
        ->fillForm(['storekeeper_whatsapp' => '08111111111'])
        ->call('save');

    $second = actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)
        ->assertFormSet(['storekeeper_whatsapp' => null])
        ->fillForm(['storekeeper_whatsapp' => '08122222222'])
        ->call('save');

    expect(VendorNotificationSetting::forVendor($first['vendor'])->fresh()->storekeeper_whatsapp)->toBe('08111111111')
        ->and(VendorNotificationSetting::forVendor($second['vendor'])->fresh()->storekeeper_whatsapp)->toBe('08122222222');
});

test('the test-reminder action warns instead of sending when no number is saved', function () {
    actAsSettingsVendor();

    Livewire::test(NotificationSettings::class)
        ->callAction('sendTestReminder')
        ->assertNotified();

    expect(\App\Models\DeliveryMessage::count())->toBe(0);
});
