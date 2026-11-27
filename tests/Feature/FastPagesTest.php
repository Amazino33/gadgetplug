<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use App\Support\RequestMemo;
use Database\Seeders\VendorPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Pages open fast: answers asked many times a page are worked out once per
 * request (RequestMemo), and are forgotten the moment something changes them.
 * The dashboard's figures load just after the page.
 */

function fpShop(): array
{
    (new VendorPermissionsSeeder())->run();
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Fast Shop ' . uniqid()]);
    VendorRoles::seedFor($vendor);

    return [$owner, $vendor];
}

test('the super admin question is asked once a request, and a new super admin counts at once', function () {
    $user = User::factory()->create();

    $queries = 0;
    DB::listen(function ($q) use (&$queries) { if (str_contains($q->sql, 'model_has_roles')) { $queries++; } });

    expect($user->isSuperAdmin())->toBeFalse()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($user->isSuperAdmin())->toBeFalse()
        ->and($queries)->toBe(1);

    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    expect($user->isSuperAdmin())->toBeTrue();
});

test('a permission given to a role during the request counts at once', function () {
    [$owner, $vendor] = fpShop();
    $staff = User::factory()->create();
    $vendor->users()->attach($staff->id);
    setPermissionsTeamId($vendor->id);
    $role = Role::create(['name' => 'tester-' . uniqid(), 'guard_name' => 'web', 'team_id' => $vendor->id]);
    $staff->assignRole($role);
    Permission::firstOrCreate(['name' => 'export_products', 'guard_name' => 'web']);

    expect($staff->hasVendorPermission($vendor->id, 'export_products'))->toBeFalse();

    $role->givePermissionTo('export_products');

    expect($staff->fresh()->hasVendorPermission($vendor->id, 'export_products'))->toBeTrue()
        ->and($staff->hasVendorPermission($vendor->id, 'export_products'))->toBeTrue();
});

test('owning a shop is remembered, and a shop handed to someone else is forgotten', function () {
    [$owner, $vendor] = fpShop();
    $other = User::factory()->create();

    expect($owner->ownsVendor($vendor->id))->toBeTrue()
        ->and($other->ownsVendor($vendor->id))->toBeFalse();

    $vendor->update(['user_id' => $other->id]);

    expect($other->ownsVendor($vendor->id))->toBeTrue()
        ->and($owner->ownsVendor($vendor->id))->toBeFalse();
});

test('a new request starts with nothing remembered', function () {
    RequestMemo::get()->remember('test:x', fn () => 1);
    app()->forgetScopedInstances();

    expect(RequestMemo::get()->remember('test:x', fn () => 2))->toBe(2);
});

test('the dashboard opens at once, with its figures loading just after', function () {
    // Other tests switch lazy loading off for themselves; it is on in real use.
    \Livewire\Features\SupportLazyLoading\SupportLazyLoading::$disableWhileTesting = false;
    [$owner, $vendor] = fpShop();

    $this->actingAs($owner)
        ->get("/plug/{$vendor->slug}/dashboard")
        ->assertOk()
        ->assertSee("Loading today's figures", false);
});
