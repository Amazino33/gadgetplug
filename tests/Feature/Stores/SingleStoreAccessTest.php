<?php

use App\Filament\Vendor\Resources\Procurements\ProcurementResource;
use App\Models\Procurement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Branch scoping is a real control once a vendor has two shops: goods sent to
// one are not the other's to receive. It is meaningless for a vendor that
// never opened a second one — and worse than meaningless, because most such
// vendors have no store_user rows at all, having never been shown the
// concept. Read literally that says "this person may work in none of our
// stores", which locked a storekeeper out of the only shop there is.

/** A shop with no branches — just whatever store it was created with. */
function soloVendor(): array
{
    $oga    = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $oga->id, 'name' => 'Solo Shop '.uniqid()]);

    return ['vendor' => $vendor, 'oga' => $oga, 'shop' => $vendor->defaultStore];
}

/** Staff with no branch assignment at all, which is the normal case here. */
function unassignedStaff(): User
{
    return User::factory()->create();
}

function soloOrder(Vendor $vendor, ?Store $store): Procurement
{
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Solo Supplier '.uniqid()]);

    return Procurement::create([
        'vendor_id'      => $vendor->id,
        'supplier_id'    => $supplier->id,
        'store_id'       => $store?->id,
        'reference'      => 'PO-'.strtoupper(Str::random(8)),
        'status'         => 'pending',
        'created_by'     => $vendor->user_id,
        'total_cost'     => 0,
        'amount_paid'    => 0,
        'payment_status' => 'credit',
        'payment_method' => 'cash',
    ]);
}

function soloPanel(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

describe('a shop with no branches', function () {
    test('gives its staff the one shop, without anyone assigning them to it', function () {
        $c = soloVendor();

        $reachable = ActiveStore::accessibleFor($c['vendor'], unassignedStaff());

        expect($reachable->pluck('id')->all())->toBe([$c['shop']->id]);
    });

    test('lets unassigned staff receive a delivery sent to it', function () {
        $c = soloVendor();
        $storekeeper = unassignedStaff();

        $order = soloOrder($c['vendor'], $c['shop']);

        soloPanel($c['vendor'], $storekeeper);

        expect(ProcurementResource::canApprove($order))->toBeTrue();
    });

    test('shows unassigned staff the delivery in the list', function () {
        $c = soloVendor();
        $storekeeper = unassignedStaff();

        $order = soloOrder($c['vendor'], $c['shop']);

        soloPanel($c['vendor'], $storekeeper);

        expect(ProcurementResource::getEloquentQuery()->pluck('procurements.id')->all())
            ->toContain($order->id);
    });

    test('resolves an active store instead of stranding them on the selector', function () {
        $c = soloVendor();

        expect(ActiveStore::get($c['vendor'], unassignedStaff())?->id)->toBe($c['shop']->id);
    });
});

// The moment a second shop exists the restriction means something again, so
// none of the above may leak into it.
describe('once a second branch exists', function () {
    test('unassigned staff reach nothing', function () {
        $c = soloVendor();
        Store::create(['vendor_id' => $c['vendor']->id, 'name' => 'Second Branch']);

        expect(ActiveStore::accessibleFor($c['vendor'], unassignedStaff()))->toBeEmpty();
    });

    test('assigned staff reach only their own branch', function () {
        $c = soloVendor();
        $second = Store::create(['vendor_id' => $c['vendor']->id, 'name' => 'Second Branch']);

        $staff = unassignedStaff();
        $second->users()->attach($staff->id);

        expect(ActiveStore::accessibleFor($c['vendor'], $staff)->pluck('id')->all())->toBe([$second->id]);
    });

    test('a delivery to the other branch stays out of reach', function () {
        $c = soloVendor();
        $second = Store::create(['vendor_id' => $c['vendor']->id, 'name' => 'Second Branch']);

        $staff = unassignedStaff();
        $second->users()->attach($staff->id);

        $toFirst = soloOrder($c['vendor'], $c['shop']);

        soloPanel($c['vendor'], $staff);

        expect(ProcurementResource::canApprove($toFirst))->toBeFalse();
    });

    test('the oga still reaches both', function () {
        $c = soloVendor();
        $second = Store::create(['vendor_id' => $c['vendor']->id, 'name' => 'Second Branch']);

        expect(ActiveStore::accessibleFor($c['vendor'], $c['oga'])->pluck('id')->all())
            ->toContain($c['shop']->id)
            ->toContain($second->id);
    });
});
