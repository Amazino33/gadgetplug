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

test('the engaged visit reward controls persist, including the kill switch', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    // Defaults out of the migration — ₦2 a visit, on.
    Livewire::test(AffiliateSettings::class)
        ->assertSet('data.click_rewards_enabled', true)
        ->assertSet('data.click_reward_amount', '2.00')
        ->fillForm([
            'click_rewards_enabled'       => false,
            'click_reward_amount'         => 3.50,
            'click_reward_daily_cap'      => 500,
            'click_reward_daily_ip_limit' => 2,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = AffiliateSetting::current();

    expect($settings->click_rewards_enabled)->toBeFalse()
        ->and((float) $settings->click_reward_amount)->toBe(3.5)
        ->and((float) $settings->click_reward_daily_cap)->toBe(500.0)
        ->and($settings->click_reward_daily_ip_limit)->toBe(2);
});

test('the Plug Points and daily-share controls persist, so no reward number is hardcoded', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->actingAs($admin);

    Livewire::test(AffiliateSettings::class)
        ->fillForm([
            'naira_per_point'         => 0.75,
            'min_points_conversion'   => 2500,
            'share_timezone'          => 'Africa/Lagos',
            'share_window_opens_at'   => '09:30',
            'share_window_closes_at'  => '21:00',
            'daily_share_points_cap'  => 90,
            'streak_bonus_points'     => 40,
            'streak_bonus_every_days' => 5,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = AffiliateSetting::current();

    expect((float) $settings->naira_per_point)->toBe(0.75)
        ->and($settings->min_points_conversion)->toBe(2500)
        ->and($settings->share_timezone)->toBe('Africa/Lagos')
        ->and($settings->daily_share_points_cap)->toBe(90)
        ->and($settings->streak_bonus_points)->toBe(40)
        ->and($settings->streak_bonus_every_days)->toBe(5)
        ->and($settings->share_window_opens_at)->toContain('09:30')
        ->and($settings->share_window_closes_at)->toContain('21:00');
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
