<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

it('reports orphaned roles without deleting them unless forced', function () {
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Ghost Store']);
    VendorRoles::seedFor($vendor);

    $vendorId = $vendor->id;
    DB::table('vendors')->where('id', $vendorId)->delete();

    $this->artisan('roles:purge-orphans')
        ->expectsOutputToContain('Re-run with --force')
        ->assertSuccessful();

    expect(Role::where('team_id', $vendorId)->count())->toBeGreaterThan(0);
});

it('removes orphaned roles along with their assignments and grants', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Ghost Store']);
    VendorRoles::seedFor($vendor);

    $role = Role::where('team_id', $vendor->id)->where('name', 'storekeeper')->first();

    DB::table(config('permission.table_names.model_has_roles'))->insert([
        'role_id'    => $role->id,
        'model_type' => User::class,
        'model_id'   => $staff->id,
        'team_id'    => $vendor->id,
    ]);

    $vendorId = $vendor->id;
    DB::table('vendors')->where('id', $vendorId)->delete();

    $this->artisan('roles:purge-orphans', ['--force' => true])->assertSuccessful();

    expect(Role::where('team_id', $vendorId)->count())->toBe(0)
        ->and(DB::table(config('permission.table_names.model_has_roles'))->where('team_id', $vendorId)->count())->toBe(0);
});

it('leaves roles belonging to live vendors alone', function () {
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Live Store']);
    VendorRoles::seedFor($vendor);

    $before = Role::where('team_id', $vendor->id)->count();
    expect($before)->toBeGreaterThan(0);

    $this->artisan('roles:purge-orphans', ['--force' => true])->assertSuccessful();

    expect(Role::where('team_id', $vendor->id)->count())->toBe($before);
});

it('leaves global roles alone', function () {
    // super_admin and friends have a null team_id and belong to no vendor.
    Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null]);

    $this->artisan('roles:purge-orphans', ['--force' => true])->assertSuccessful();

    expect(Role::where('name', 'super_admin')->count())->toBe(1);
});
