<?php

use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\Store;
use App\Models\User;
use App\Services\StockTake\StockTakeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Step 2: the three Stock Take permissions, who holds them by default, and the
 * branch and separation-of-duty rules on top of them.
 */

function stEntry(array $ctx, StockTakeTask $task, $product, User $by, $aisle): StockTakeEntry
{
    return StockTakeEntry::create([
        'vendor_id'          => $ctx['vendor']->id,
        'store_id'           => $ctx['store']->id,
        'stock_take_task_id' => $task->id,
        'aisle_id'           => $aisle->id,
        'product_id'         => $product->id,
        'counted_by'         => $by->id,
        'counted_qty_base'   => 48,
        'counted_at'         => now(),
    ]);
}

function stResult(array $ctx, StockTakeTask $task, $product, ?int $recountOf = null): StockTakeResult
{
    return StockTakeResult::create([
        'vendor_id'           => $ctx['vendor']->id,
        'store_id'            => $ctx['store']->id,
        'stock_take_id'       => $task->stock_take_id,
        'stock_take_task_id'  => $task->id,
        'product_id'          => $product->id,
        'total_counted_base'  => 48,
        'counted_at'          => now(),
        'system_qty_at_count' => 50,
        'variance_qty'        => -2,
        'recount_of_id'       => $recountOf,
    ]);
}

test('default roles: admins run stock takes and map aisles, storekeepers count', function () {
    $ctx = stockTakeContext();
    setPermissionsTeamId($ctx['vendor']->id);

    $role = fn (string $name) => Role::where('team_id', $ctx['vendor']->id)->where('name', $name)->first();

    foreach (['store_admin', 'inventory_manager'] as $manager) {
        expect($role($manager)->hasPermissionTo('manage_stock_takes'))->toBeTrue()
            ->and($role($manager)->hasPermissionTo('map_aisles'))->toBeTrue()
            ->and($role($manager)->hasPermissionTo('perform_stock_takes'))->toBeFalse();
    }

    expect($role('storekeeper')->hasPermissionTo('perform_stock_takes'))->toBeTrue()
        ->and($role('storekeeper')->hasPermissionTo('manage_stock_takes'))->toBeFalse()
        ->and($role('storekeeper')->hasPermissionTo('map_aisles'))->toBeFalse()
        ->and($role('member')->hasPermissionTo('perform_stock_takes'))->toBeFalse();
});

test('the migration grants the permissions to existing default roles and leaves custom roles alone', function () {
    $ctx = stockTakeContext();
    setPermissionsTeamId($ctx['vendor']->id);

    $admin  = Role::where('team_id', $ctx['vendor']->id)->where('name', 'store_admin')->first();
    $keeper = Role::where('team_id', $ctx['vendor']->id)->where('name', 'storekeeper')->first();
    $custom = Role::create(['name' => 'night_shift', 'guard_name' => 'web', 'team_id' => $ctx['vendor']->id]);

    // As a vendor's roles stood before Stock Take shipped.
    $admin->revokePermissionTo(['manage_stock_takes', 'map_aisles']);
    $keeper->revokePermissionTo('perform_stock_takes');

    (require database_path('migrations/2026_11_24_110300_add_stock_take_permissions.php'))->up();

    expect($admin->fresh()->hasPermissionTo('manage_stock_takes'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('map_aisles'))->toBeTrue()
        ->and($keeper->fresh()->hasPermissionTo('perform_stock_takes'))->toBeTrue()
        ->and($custom->fresh()->permissions)->toHaveCount(0);

    // Running it twice adds nothing twice.
    (require database_path('migrations/2026_11_24_110300_add_stock_take_permissions.php'))->up();

    expect(Permission::where('name', 'manage_stock_takes')->count())->toBe(1);
});

test('the permissions can be granted to a role on the Roles screen', function () {
    expect(App\Filament\Vendor\Resources\Roles\RoleResource::GRANTABLE_PERMISSIONS)
        ->toContain('manage_stock_takes', 'perform_stock_takes', 'map_aisles');
});

test('with several branches, a permission counts only where the person is assigned; the owner everywhere', function () {
    $ctx    = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);
    $v      = $ctx['vendor']->id;

    expect(StockTakeAccess::canManage($ctx['admin'], $v, $ctx['store']->id))->toBeTrue()
        ->and(StockTakeAccess::canManage($ctx['admin'], $v, $branch->id))->toBeFalse()
        ->and(StockTakeAccess::canMap($ctx['admin'], $v, $ctx['store']->id))->toBeTrue()
        ->and(StockTakeAccess::canPerform($ctx['keeperA'], $v, $ctx['store']->id))->toBeTrue()
        ->and(StockTakeAccess::canPerform($ctx['keeperA'], $v, $branch->id))->toBeFalse()
        ->and(StockTakeAccess::canManage($ctx['keeperA'], $v, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canManage($ctx['owner'], $v, $branch->id))->toBeTrue();
});

test('nobody from another vendor gets anything, whatever their role there', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();

    expect(StockTakeAccess::canManage($other['admin'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canPerform($other['keeperA'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canManage($other['owner'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();
});

test('only the assignee works on an open task', function () {
    $ctx  = stockTakeContext();
    $task = stTask($ctx, stStockTake($ctx), $ctx['keeperA']);

    expect(StockTakeAccess::canWorkOn($ctx['keeperA'], $task))->toBeTrue()
        ->and(StockTakeAccess::canWorkOn($ctx['keeperB'], $task))->toBeFalse();

    $task->update(['status' => StockTakeTask::STATUS_SUBMITTED]);

    expect(StockTakeAccess::canWorkOn($ctx['keeperA'], $task->fresh()))->toBeFalse();
});

test('a mapping task lets its assignee map the aisles on that task, and only while it is open', function (string $closedAs) {
    $ctx     = stockTakeContext();
    $mine    = stAisle($ctx, 'Aisle 3');
    $other   = stAisle($ctx, 'Aisle 9');
    $task    = stTask($ctx, stStockTake($ctx), $ctx['keeperA'], StockTakeTask::TYPE_MAPPING);
    $task->attachAisles([$mine->id]);

    expect(StockTakeAccess::canMap($ctx['keeperA'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canWorkOn($ctx['keeperA'], $task))->toBeTrue()
        ->and(StockTakeAccess::canMapAisle($ctx['keeperA'], $mine))->toBeTrue()
        ->and(StockTakeAccess::canMapAisle($ctx['keeperA'], $other))->toBeFalse()
        // Someone else's mapping task grants them nothing.
        ->and(StockTakeAccess::canMapAisle($ctx['keeperB'], $mine))->toBeFalse();

    $task->update(['status' => $closedAs]);

    expect(StockTakeAccess::canMapAisle($ctx['keeperA'], $mine))->toBeFalse()
        ->and(StockTakeAccess::canWorkOn($ctx['keeperA'], $task->fresh()))->toBeFalse();
})->with([StockTakeTask::STATUS_SUBMITTED, StockTakeTask::STATUS_CANCELLED]);

test('map_aisles maps any aisle at the branch, without a task', function () {
    $ctx = stockTakeContext();

    expect(StockTakeAccess::canMapAisle($ctx['admin'], stAisle($ctx, 'Aisle 1')))->toBeTrue();
});

test('in a shop with one branch, staff need no branch assignment', function () {
    $ctx = stockTakeContext();

    // As most single-branch shops are: nobody was ever assigned to anything.
    Illuminate\Support\Facades\DB::table('store_user')->delete();

    expect(StockTakeAccess::canManage($ctx['admin'], $ctx['vendor']->id, $ctx['store']->id))->toBeTrue()
        ->and(StockTakeAccess::canPerform($ctx['keeperA'], $ctx['vendor']->id, $ctx['store']->id))->toBeTrue();

    // A second branch makes assignment a real control again.
    Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);

    expect(StockTakeAccess::canManage($ctx['admin'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse()
        ->and(StockTakeAccess::canManage($ctx['owner'], $ctx['vendor']->id, $ctx['store']->id))->toBeTrue();
});

test('a branch of another vendor is never reachable, not even by an owner', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();

    expect(StockTakeAccess::canManage($ctx['owner'], $ctx['vendor']->id, $other['store']->id))->toBeFalse();
});

test('nobody decides on a product they counted; the owner may, with a note', function () {
    $ctx     = stockTakeContext();
    $task    = stTask($ctx, stStockTake($ctx), $ctx['admin'], over: ['status' => StockTakeTask::STATUS_SUBMITTED]);
    $aisle   = stAisle($ctx, 'Aisle 1');
    $product = stProduct($ctx);

    // The admin counted it themselves.
    stEntry($ctx, $task, $product, $ctx['admin'], $aisle);
    $result = stResult($ctx, $task, $product);

    expect(StockTakeAccess::canDecide($ctx['admin'], $result))->toBeFalse();

    // The owner counted another product, and may still decide on it.
    $mine = stProduct($ctx, 'Owner counted');
    stEntry($ctx, $task, $mine, $ctx['owner'], $aisle);
    $ownResult = stResult($ctx, $task, $mine);

    expect(StockTakeAccess::canDecide($ctx['owner'], $ownResult))->toBeTrue()
        ->and(StockTakeAccess::needsOverrideNote($ctx['owner'], $ownResult))->toBeTrue()
        ->and(StockTakeAccess::needsOverrideNote($ctx['owner'], $result))->toBeFalse();
});

test('having counted either side of a recount pair rules you out of deciding on it', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $aisle     = stAisle($ctx, 'Aisle 1');
    $product   = stProduct($ctx);

    // The admin did the original count; a storekeeper the recount.
    $original = stTask($ctx, $stockTake, $ctx['admin'], over: ['status' => StockTakeTask::STATUS_SUBMITTED]);
    stEntry($ctx, $original, $product, $ctx['admin'], $aisle);
    $first = stResult($ctx, $original, $product);

    $recount = stTask($ctx, $stockTake, $ctx['keeperB'], StockTakeTask::TYPE_RECOUNT, [
        'parent_task_id' => $original->id,
        'status'         => StockTakeTask::STATUS_SUBMITTED,
    ]);
    stEntry($ctx, $recount, $product, $ctx['keeperB'], $aisle);
    $second = stResult($ctx, $recount, $product, $first->id);

    expect(StockTakeAccess::canDecide($ctx['admin'], $second))->toBeFalse()
        ->and(StockTakeAccess::canDecide($ctx['admin'], $first->fresh()))->toBeFalse();
});
