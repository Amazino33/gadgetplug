<?php

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A vendor with two branches: the default one, and Oraimo Store.
 *
 * @return array{vendor: Vendor, oga: User, oraimo: Store, accessories: Store}
 */
function branchVendor(): array
{
    $oga = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $oga->id, 'name' => 'Branch Vendor '.uniqid()]);
    $oraimo = Store::create(['vendor_id' => $vendor->id, 'name' => 'Oraimo Store']);

    return [
        'vendor'      => $vendor,
        'oga'         => $oga,
        'oraimo'      => $oraimo,
        'accessories' => $vendor->defaultStore,
    ];
}

/** A storekeeper who works at exactly one branch. */
function branchStaff(Vendor $vendor, ?Store $store): User
{
    $user = User::factory()->create();

    if ($store) {
        $store->users()->attach($user->id);
    }

    return $user;
}

function branchOrder(Vendor $vendor, ?Store $store, ?User $creator = null): Procurement
{
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Branch Supplier '.uniqid()]);

    return Procurement::create([
        'vendor_id'      => $vendor->id,
        'supplier_id'    => $supplier->id,
        'store_id'       => $store?->id,
        'reference'      => 'PO-'.strtoupper(Str::random(8)),
        'status'         => 'pending',
        'created_by'     => ($creator ?? User::find($vendor->user_id))->id,
        'total_cost'     => 0,
        'amount_paid'    => 0,
        'payment_status' => 'credit',
        'payment_method' => 'cash',
    ]);
}

function branchPanel(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

/** The ids this user can see through the resource's own query. */
function visibleOrderIds(): array
{
    return ProcurementResource::getEloquentQuery()->pluck('procurements.id')->all();
}

describe('who sees a delivery', function () {
    test('a delivery sent to Oraimo is invisible to the Accessories storekeeper', function () {
        $c = branchVendor();
        $atAccessories = branchStaff($c['vendor'], $c['accessories']);

        $toOraimo = branchOrder($c['vendor'], $c['oraimo']);

        branchPanel($c['vendor'], $atAccessories);

        expect(visibleOrderIds())->not->toContain($toOraimo->id);
    });

    test('and visible to the Oraimo storekeeper', function () {
        $c = branchVendor();
        $atOraimo = branchStaff($c['vendor'], $c['oraimo']);

        $toOraimo = branchOrder($c['vendor'], $c['oraimo']);

        branchPanel($c['vendor'], $atOraimo);

        expect(visibleOrderIds())->toContain($toOraimo->id);
    });

    test('the oga sees every branch', function () {
        $c = branchVendor();

        $toOraimo = branchOrder($c['vendor'], $c['oraimo']);
        $toAccessories = branchOrder($c['vendor'], $c['accessories']);

        branchPanel($c['vendor'], $c['oga']);

        expect(visibleOrderIds())
            ->toContain($toOraimo->id)
            ->toContain($toAccessories->id);
    });

    test('whoever recorded the purchase keeps sight of it, wherever they work', function () {
        $c = branchVendor();
        $admin = branchStaff($c['vendor'], $c['accessories']);

        // Recorded by someone at Accessories, sent to Oraimo.
        $order = branchOrder($c['vendor'], $c['oraimo'], $admin);

        branchPanel($c['vendor'], $admin);

        expect(visibleOrderIds())->toContain($order->id);
    });

    test('an order with no destination is still visible to everybody', function () {
        $c = branchVendor();
        $atOraimo = branchStaff($c['vendor'], $c['oraimo']);

        // These predate the destination field and belong to no branch.
        $legacy = branchOrder($c['vendor'], null);

        branchPanel($c['vendor'], $atOraimo);

        expect(visibleOrderIds())->toContain($legacy->id);
    });
});

describe('who may approve it', function () {
    test('only the receiving branch', function () {
        $c = branchVendor();
        $atOraimo = branchStaff($c['vendor'], $c['oraimo']);
        $atAccessories = branchStaff($c['vendor'], $c['accessories']);

        $toOraimo = branchOrder($c['vendor'], $c['oraimo']);

        branchPanel($c['vendor'], $atOraimo);
        expect(ProcurementResource::canApprove($toOraimo))->toBeTrue();

        branchPanel($c['vendor'], $atAccessories);
        expect(ProcurementResource::canApprove($toOraimo))->toBeFalse();
    });

    test('the creator seeing their own order is not the same as being able to approve it', function () {
        $c = branchVendor();
        $admin = branchStaff($c['vendor'], $c['accessories']);

        $toOraimo = branchOrder($c['vendor'], $c['oraimo'], $admin);

        branchPanel($c['vendor'], $admin);

        // Visible, so they can follow it through — but they did not take
        // delivery of it, so they cannot say it arrived.
        expect(visibleOrderIds())->toContain($toOraimo->id)
            ->and(ProcurementResource::canApprove($toOraimo))->toBeFalse();
    });

    test('the oga can approve for any branch', function () {
        $c = branchVendor();
        $toOraimo = branchOrder($c['vendor'], $c['oraimo']);

        branchPanel($c['vendor'], $c['oga']);

        expect(ProcurementResource::canApprove($toOraimo))->toBeTrue();
    });

    test('an order with no destination can still be approved by anyone', function () {
        $c = branchVendor();
        $atOraimo = branchStaff($c['vendor'], $c['oraimo']);
        $legacy = branchOrder($c['vendor'], null);

        branchPanel($c['vendor'], $atOraimo);

        expect(ProcurementResource::canApprove($legacy))->toBeTrue();
    });
});

describe('the badge follows the same rule', function () {
    test('a storekeeper is not badged about another branch\'s delivery', function () {
        $c = branchVendor();
        $atAccessories = branchStaff($c['vendor'], $c['accessories']);

        branchOrder($c['vendor'], $c['oraimo']);
        branchOrder($c['vendor'], $c['oraimo']);

        branchPanel($c['vendor'], $atAccessories);

        // Being told there is something to do, then finding an empty list, is
        // worse than not being told.
        expect(ProcurementResource::getNavigationBadge())->toBeNull();
    });

    test('the oga is badged with every branch\'s', function () {
        $c = branchVendor();

        branchOrder($c['vendor'], $c['oraimo']);
        branchOrder($c['vendor'], $c['accessories']);

        branchPanel($c['vendor'], $c['oga']);

        expect(ProcurementResource::getNavigationBadge())->toBe('2');
    });
});

describe('another vendor', function () {
    test('never sees this vendor\'s deliveries', function () {
        $mine = branchVendor();
        $theirs = branchVendor();

        branchOrder($theirs['vendor'], $theirs['oraimo']);
        $ours = branchOrder($mine['vendor'], $mine['oraimo']);

        branchPanel($mine['vendor'], $mine['oga']);

        expect(visibleOrderIds())->toBe([$ours->id]);
    });
});
