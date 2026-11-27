<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

function superAdminUser(): User
{
    $admin = User::factory()->create();
    setPermissionsTeamId(null);
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

function aShop(): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Shop ' . uniqid()]);
}

test('a super admin has every permission in any shop, without holding a role there', function () {
    $admin = superAdminUser();
    $shop  = aShop();

    expect($admin->hasVendorPermission($shop->id, 'manage_inventory'))->toBeTrue()
        ->and($admin->hasVendorPermission($shop->id, ['edit_products', 'export_sales']))->toBeTrue()
        ->and($admin->hasVendorPermission($shop->id, 'a_permission_nobody_defined'))->toBeTrue();
});

test('inside a shop\'s panel, the super admin still passes every gate', function () {
    $admin = superAdminUser();
    $shop  = aShop();

    // The shop's team is the current one, as inside its panel.
    setPermissionsTeamId($shop->id);
    $admin->unsetRelation('roles');

    expect(Gate::forUser($admin)->allows('anything_at_all'))->toBeTrue();
});

test('everyone else is still checked against the shop as before', function () {
    $shop     = aShop();
    $stranger = User::factory()->create();
    $owner    = $shop->owner ?? User::find($shop->user_id);

    expect($stranger->hasVendorPermission($shop->id, 'manage_inventory'))->toBeFalse()
        ->and($owner->hasVendorPermission($shop->id, 'manage_inventory'))->toBeTrue()
        ->and(Gate::forUser($stranger)->allows('anything_at_all'))->toBeFalse();
});
