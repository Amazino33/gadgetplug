<?php

use App\Models\AuditSession;
use App\Models\BlindCountEntry;
use App\Models\BlindCountSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Filament\Vendor\Pages\BlindCount;
use Filament\Facades\Filament;
use Livewire\Livewire;

// Filament's setTenant() fires an event needing an authenticated user, so it
// can only run after actingAs() — same ordering as BlindCountSoloTest.
function enterVendorPanel(Vendor $vendor): void
{
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

// The baseline has to be frozen when the count is taken. Read live afterwards,
// every sale between counting and resolving silently inflates the "shortage" —
// which is the number someone gets held to.

function baselineContext(): array
{
    (new Database\Seeders\VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create([
        'user_id'                      => $owner->id,
        'name'                         => 'Leisure Hub',
        'pos_blind_count_participants' => 1,
    ]);
    $vendor->users()->attach($staff->id);

    App\Services\VendorRoles::seedFor($vendor);
    setPermissionsTeamId($vendor->id);
    $staff->assignRole('storekeeper');

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 10,
        'reserved_stock' => 0,
        'status'         => 'published',
        'show_online'    => true,
        'show_in_pos'    => true,
    ]);

    return compact('owner', 'staff', 'vendor', 'product');
}

it('freezes the system quantity when a solo count is submitted', function () {
    $c = baselineContext();

    $this->actingAs($c['staff']);
    enterVendorPanel($c['vendor']);

    Livewire::test(BlindCount::class)
        ->call('startSession')
        ->set('count', 7)
        ->call('submitAll');

    $audit = AuditSession::where('product_id', $c['product']->id)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->system_quantity)->toBe(10)
        ->and($audit->countedVariance())->toBe(-3);
});

it('measures variance against the frozen baseline even after stock moves', function () {
    $c = baselineContext();

    $audit = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'system_quantity'  => 10,
        'storekeeper_a_id' => $c['staff']->id,
        'count_a'          => 7,
        'count_b'          => 7,
        'storekeeper_b_id' => $c['owner']->id,
        'status'           => 'verified',
    ]);

    // Six more sold after the count. The count still found 3 missing, not 9.
    $c['product']->update(['stock_quantity' => 4]);

    expect($audit->fresh()->countedVariance())->toBe(-3);
});

it('reports an unmeasurable variance rather than a false zero on legacy rows', function () {
    $c = baselineContext();

    $audit = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'system_quantity'  => null,   // predates baseline capture
        'storekeeper_a_id' => $c['staff']->id,
        'count_a'          => 7,
        'status'           => 'verified',
    ]);

    expect($audit->countedVariance())->toBeNull();
});

it('treats a manager override as the settled figure', function () {
    $c = baselineContext();

    $audit = AuditSession::create([
        'vendor_id'              => $c['vendor']->id,
        'product_id'             => $c['product']->id,
        'system_quantity'        => 10,
        'storekeeper_a_id'       => $c['staff']->id,
        'count_a'                => 7,
        'count_b'                => 5,
        'storekeeper_b_id'       => $c['owner']->id,
        'manager_override_count' => 6,
        'status'                 => 'resolved_by_override',
    ]);

    expect($audit->countedQuantity())->toBe(6)
        ->and($audit->countedVariance())->toBe(-4);
});
