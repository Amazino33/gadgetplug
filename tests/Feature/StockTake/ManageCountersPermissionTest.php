<?php

use App\Filament\Vendor\Resources\Counters\CounterResource;
use App\Filament\Vendor\Resources\Roles\RoleResource;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeCounters;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * "Manage Counters": the Counters screen (add, turn off, new code, sign-in QR)
 * has its own permission, apart from running the stock take. And every shop
 * has its Counter role, on the Roles screen to see and change.
 */

function mcAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['store']);
}

/** A staff member holding only the given permissions, in a role of their own. */
function mcStaff(array $ctx, array $permissions): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->attach($user->id);
    $user->stores()->attach($ctx['store']->id);
    setPermissionsTeamId($ctx['vendor']->id);
    $role = Role::create(['name' => 'custom-' . uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['vendor']->id]);
    $role->givePermissionTo($permissions);
    $user->assignRole($role);

    return $user;
}

test('a supervisor with only Manage Counters runs the counters, and nothing else of the stock take', function () {
    $ctx  = stockTakeContext();
    $user = mcStaff($ctx, ['manage_counters']);
    mcAs($ctx, $user);

    expect(CounterResource::canAccess())->toBeTrue();

    $made = app(StockTakeCounters::class)->add($user, $ctx['vendor'], 'Ngozi Eze', null, [$ctx['store']->id]);
    expect($made['counter']->name)->toBe('Ngozi Eze');

    expect(\App\Services\StockTake\StockTakeAccess::canManage($user, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
});

test('Manage Stock Takes alone no longer opens the Counters screen', function () {
    $ctx  = stockTakeContext();
    $user = mcStaff($ctx, ['manage_stock_takes']);
    mcAs($ctx, $user);

    expect(CounterResource::canAccess())->toBeFalse();
    expect(fn () => app(StockTakeCounters::class)->add($user, $ctx['vendor'], 'Ngozi Eze', null, [$ctx['store']->id]))
        ->toThrow(AuthorizationException::class);
});

test('Manage Counters is on the Roles screen, and the built-in managers have it', function () {
    $ctx = stockTakeContext();

    expect(RoleResource::GRANTABLE_PERMISSIONS)->toContain('manage_counters');
    mcAs($ctx, $ctx['admin']);
    expect(CounterResource::canAccess())->toBeTrue();
});

test('on release: every role that could manage stock takes keeps the Counters screen, and every shop gets its Counter role', function () {
    $ctx = stockTakeContext();
    $custom = mcStaff($ctx, ['manage_stock_takes']);
    $customRole = $custom->roles()->first();
    $bare = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'No Counter Role ' . uniqid()]);
    DB::table('roles')->where('name', 'counter')->where('team_id', $bare->id)->delete();

    $migration = require database_path('migrations/2026_11_27_120000_add_manage_counters_permission.php');
    $migration->down();
    $migration->up();

    expect(DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_id', $customRole->id)->where('permissions.name', 'manage_counters')->exists())->toBeTrue();

    expect(DB::table('roles')->where('name', 'counter')->where('team_id', $bare->id)->exists())->toBeTrue();
});
