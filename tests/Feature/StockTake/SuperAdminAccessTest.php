<?php

use App\Models\User;
use App\Services\StockTake\StockTakeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/** The platform's super admin may do everything in a shop that has Stock Take on. */
function stSuperAdmin(): User
{
    $admin = User::factory()->create();
    setPermissionsTeamId(null);
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    return $admin;
}

test('a super admin who is not in the shop may manage, count and map there', function () {
    $ctx   = stockTakeContext();
    $admin = stSuperAdmin();
    $v     = $ctx['vendor']->id;
    $s     = $ctx['store']->id;

    expect(StockTakeAccess::canManage($admin, $v, $s))->toBeTrue()
        ->and(StockTakeAccess::canPerform($admin, $v, $s))->toBeTrue()
        ->and(StockTakeAccess::canMap($admin, $v, $s))->toBeTrue();
});

test('even a super admin gets nothing where Stock Take is switched off for the shop', function () {
    $ctx = stockTakeContext();
    $ctx['vendor']->update(['stock_take_enabled' => false]);
    $admin = stSuperAdmin();

    expect(StockTakeAccess::canManage($admin, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
});

test('someone who is not a super admin and holds no permission still gets nothing', function () {
    $ctx = stockTakeContext();
    $stranger = User::factory()->create();

    expect(StockTakeAccess::canManage($stranger, $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
});
