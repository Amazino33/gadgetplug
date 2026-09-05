<?php

use App\Filament\Vendor\Resources\Roles\RoleResource;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * The Roles screen lists permissions from a whitelist, so seeding a new one is
 * not enough to make it grantable — it has to be named there too. That gap is
 * what left manage_pickings invisible on the screen after it shipped, with no
 * way to give a storekeeper the feature that was built for them.
 */
test('a storekeeper can be granted pickings from the roles screen', function () {
    expect(RoleResource::GRANTABLE_PERMISSIONS)->toContain('manage_pickings');
});

test('writing off stays the owner\'s alone, ungrantable to any role', function () {
    // Absent on purpose: the owner gets it by ownership, and listing it would
    // let it be handed to a role.
    expect(RoleResource::GRANTABLE_PERMISSIONS)->not->toContain('write_off_picking');
});

test('every grantable permission actually exists once seeded', function () {
    (new VendorPermissionsSeeder())->run();

    $seeded = Permission::pluck('name')->all();
    $missing = array_diff(RoleResource::GRANTABLE_PERMISSIONS, $seeded);

    // A name on the screen with no permission behind it is a checkbox that
    // silently grants nothing.
    expect($missing)->toBeEmpty();
});
