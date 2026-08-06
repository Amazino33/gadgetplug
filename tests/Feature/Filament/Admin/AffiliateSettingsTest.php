<?php

use App\Filament\Pages\AffiliateSettings;
use App\Models\AffiliateSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a super admin can access the affiliate settings page', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    expect(AffiliateSettings::canAccess())->toBeTrue();
});

test('a regular vendor owner cannot access the affiliate settings page', function () {
    $owner = User::factory()->create();
    Vendor::create(['user_id' => $owner->id, 'name' => 'Regular Settings Vendor']);

    $this->actingAs($owner);

    expect(AffiliateSettings::canAccess())->toBeFalse();
});

test('saving the form persists new values to the single settings row', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    Livewire::test(AffiliateSettings::class)
        ->fillForm([
            'platform_default_rate' => 7.5,
            'return_window_days'    => 5,
            'cookie_window_days'    => 45,
            'min_payout_amount'     => 3000,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = AffiliateSetting::current();

    expect((float) $settings->platform_default_rate)->toBe(7.5)
        ->and($settings->return_window_days)->toBe(5)
        ->and($settings->cookie_window_days)->toBe(45)
        ->and((float) $settings->min_payout_amount)->toBe(3000.0);
});

test('the margin cap field round-trips between the stored fraction and the displayed percentage', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    AffiliateSetting::current()->update(['margin_cap_fraction' => 0.35, 'inactivity_demotion_days' => 21]);

    $this->actingAs($admin);

    // Mounted form should show 35, not 0.35, since the DB stores a fraction.
    Livewire::test(AffiliateSettings::class)
        ->assertSet('data.margin_cap_fraction', 35.0)
        ->fillForm(['margin_cap_fraction' => 40, 'inactivity_demotion_days' => 30])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = AffiliateSetting::current();

    expect((float) $settings->margin_cap_fraction)->toBe(0.4)
        ->and($settings->inactivity_demotion_days)->toBe(30);
});

test('the platform default reseller discount persists as a plain percentage', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    Livewire::test(AffiliateSettings::class)
        ->fillForm(['platform_default_reseller_discount' => 18.0])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) AffiliateSetting::current()->platform_default_reseller_discount)->toBe(18.0);
});
