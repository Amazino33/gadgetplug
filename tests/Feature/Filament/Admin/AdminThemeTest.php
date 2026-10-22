<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// Delivery settings rendered almost unstyled: the admin theme compiled only the
// classes Filament itself uses, so every Tailwind class in our own admin views
// was missing. The theme now scans those views (resources/css/filament/admin/
// theme.css @source lines). These check the build actually holds them and that
// the custom pages still render.

function adminThemeCss(): string
{
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

    return file_get_contents(public_path('build/'.$manifest['resources/css/filament/admin/theme.css']['file']));
}

function themeSuperAdmin(): User
{
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    return $user;
}

test('the admin panel loads its own theme', function () {
    expect(filament()->getPanel('admin')->getViteTheme())->toBe('resources/css/filament/admin/theme.css');
});

test('the built admin theme holds the classes used in custom admin views, not only Filament\'s', function () {
    $css = adminThemeCss();

    // Each of these appears only in our own views — none in Filament's.
    foreach ([
        '.min-h-\[320px\]',   // the delivery map's height
        '.bg-amber-50',       // the "no hub pinned" notice
        '.gp-card',           // All Products phone card (plain CSS in the theme)
    ] as $selector) {
        expect(str_contains($css, $selector))->toBeTrue("Missing from the admin theme build: {$selector}");
    }
});

test('the admin theme scans every place admin markup lives', function () {
    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    foreach (['app/Filament/', 'resources/views/filament/', 'resources/views/livewire/', 'resources/views/partials/'] as $path) {
        expect($theme)->toContain("@source '../../../../{$path}");
    }
});

test('the custom admin pages render', function (string $path) {
    $this->actingAs(themeSuperAdmin())
        ->get($path)
        ->assertOk()
        ->assertSee('build/assets/theme-', false);
})->with([
    'delivery settings'      => '/admin/delivery-settings',
    'messaging settings'     => '/admin/messaging-settings',
    'affiliate settings'     => '/admin/affiliate-settings',
    'affiliate payout batch' => '/admin/affiliate-payout-batch',
    'all products'           => '/admin/products',
    'dispatch'               => '/admin/orders',
]);
