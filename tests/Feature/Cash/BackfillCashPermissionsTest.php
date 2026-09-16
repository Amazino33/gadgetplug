<?php

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Vendors created before the cash-handover permissions existed have roles that
 * were synced from an older list, which leaves the handover screen offering an
 * empty dropdown. This backfills them — without resetting roles a vendor may
 * have deliberately changed themselves.
 */
function staleVendor(): Vendor
{
    $vendor = cashVendor();
    cashRoles($vendor);

    // Wind the roles back to how they looked before these permissions existed.
    foreach (['store_admin', 'inventory_manager', 'storekeeper'] as $name) {
        Role::where('name', $name)->where('team_id', $vendor->id)->first()
            ?->revokePermissionTo(['submit_cash', 'receive_cash']);
    }

    return $vendor;
}

test('a vendor whose roles predate the cash permissions gets them back', function () {
    $vendor = staleVendor();

    $keeper = Role::where('name', 'storekeeper')->where('team_id', $vendor->id)->first();
    $admin = Role::where('name', 'store_admin')->where('team_id', $vendor->id)->first();

    expect($keeper->hasPermissionTo('submit_cash'))->toBeFalse()
        ->and($admin->hasPermissionTo('receive_cash'))->toBeFalse();

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id])
        ->assertSuccessful();

    expect($keeper->fresh()->hasPermissionTo('submit_cash'))->toBeTrue()
        ->and($admin->fresh()->hasPermissionTo('receive_cash'))->toBeTrue();
});

test('the storekeeper still never gets receive_cash', function () {
    $vendor = staleVendor();

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id])->assertSuccessful();

    // The whole value of a handover record is two different names on it.
    expect(Role::where('name', 'storekeeper')->where('team_id', $vendor->id)->first()
        ->hasPermissionTo('receive_cash'))->toBeFalse();
});

test('it never takes away a permission a vendor added themselves', function () {
    $vendor = staleVendor();

    // A vendor has decided their order managers may adjust stock. That is their
    // business, and a backfill that quietly reverted it would be worse than the
    // gap it set out to close.
    $orderManager = Role::where('name', 'order_manager')->where('team_id', $vendor->id)->first();
    $orderManager->givePermissionTo('adjust_stock');

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id])->assertSuccessful();

    expect($orderManager->fresh()->hasPermissionTo('adjust_stock'))->toBeTrue();
});

test('running it twice changes nothing the second time', function () {
    $vendor = staleVendor();

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id])->assertSuccessful();

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

test('a dry run reports what it would do and does nothing', function () {
    $vendor = staleVendor();

    $this->artisan('cash:backfill-permissions', ['vendor' => $vendor->id, '--dry-run' => true])
        ->assertSuccessful();

    expect(Role::where('name', 'storekeeper')->where('team_id', $vendor->id)->first()
        ->hasPermissionTo('submit_cash'))->toBeFalse();
});
