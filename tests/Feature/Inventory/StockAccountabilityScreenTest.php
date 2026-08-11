<?php

use App\Filament\Vendor\Resources\StockAccountability\Pages\ListStockAccountability;
use App\Filament\Vendor\Resources\StockAccountability\StockAccountabilityResource;
use App\Filament\Vendor\Widgets\StockLiabilityOverview;
use App\Models\AuditSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAccountabilityEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StockAccountabilityLedger;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function screenContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    $vendor->users()->attach($staff->id);

    VendorRoles::seedFor($vendor);
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
        'status'         => 'published',
    ]);

    $audit = AuditSession::create([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'system_quantity'  => 10,
        'storekeeper_a_id' => $staff->id,
        'count_a'          => 7,
        'storekeeper_b_id' => $owner->id,
        'count_b'          => 7,
        'status'           => 'verified',
    ]);

    return compact('owner', 'staff', 'vendor', 'product', 'audit');
}

function enterPanelAs(Vendor $vendor): void
{
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);
}

it('lists accountability entries for someone allowed to review counts', function () {
    $c = screenContext();

    app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    $this->actingAs($c['owner']);
    enterPanelAs($c['vendor']);

    Livewire::test(ListStockAccountability::class)
        ->assertOk()
        ->assertCanSeeTableRecords(StockAccountabilityEntry::all());
});

it('lets the owner reverse an entry, leaving the original standing', function () {
    $c = screenContext();

    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    $this->actingAs($c['owner']);
    enterPanelAs($c['vendor']);

    Livewire::test(ListStockAccountability::class)
        ->callTableAction('reverse', $entry, ['note' => 'Found in the back room.']);

    expect(StockAccountabilityEntry::count())->toBe(2)
        ->and(StockAccountabilityEntry::where('disposition', 'reversal')->exists())->toBeTrue()
        ->and(app(StockAccountabilityLedger::class)->outstandingFor($c['staff']->id, $c['vendor']->id))
            ->toBe(0.0);
});

it('does not offer reverse to staff who are not the owner', function () {
    $c = screenContext();

    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    // Give the storekeeper the review permission so the page itself is reachable
    // — the point is that reversing still is not.
    setPermissionsTeamId($c['vendor']->id);
    $c['staff']->givePermissionTo('view_audit_sessions');

    $this->actingAs($c['staff']);
    enterPanelAs($c['vendor']);

    expect(StockAccountabilityResource::canReverse($entry))->toBeFalse();

    Livewire::test(ListStockAccountability::class)
        ->assertTableActionHidden('reverse', $entry);
});

it('will not offer reverse twice on the same entry', function () {
    $c = screenContext();
    $ledger = app(StockAccountabilityLedger::class);

    $entry = $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);
    $ledger->reverse($entry, $c['owner']->id, 'Already withdrawn.');

    $this->actingAs($c['owner']);
    enterPanelAs($c['vendor']);

    Livewire::test(ListStockAccountability::class)
        ->assertTableActionHidden('reverse', $entry);
});

it('hides money from staff without the cost price permission', function () {
    $c = screenContext();

    app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    setPermissionsTeamId($c['vendor']->id);
    $c['staff']->givePermissionTo('view_audit_sessions');

    $this->actingAs($c['staff']);
    enterPanelAs($c['vendor']);

    // Amount is derived from cost price, so it follows the same gate. The column
    // is defined but not rendered — hidden, rather than absent.
    Livewire::test(ListStockAccountability::class)
        ->assertOk()
        ->assertTableColumnHidden('amount')
        ->assertDontSee('7,710.00');

    expect(StockLiabilityOverview::canView())->toBeFalse();
});

it('totals what is owed, what was absorbed, and what nobody was named for', function () {
    $c = screenContext();
    $ledger = app(StockAccountabilityLedger::class);

    // Owed by the storekeeper: 3 short x 2,570.
    $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    // A second count, written off with nobody named.
    $second = AuditSession::create([
        'vendor_id'        => $c['vendor']->id,
        'product_id'       => $c['product']->id,
        'system_quantity'  => 10,
        'storekeeper_a_id' => $c['staff']->id,
        'count_a'          => 8,
        'storekeeper_b_id' => $c['owner']->id,
        'count_b'          => 8,
        'status'           => 'verified',
    ]);
    $ledger->attribute($second, 'written_off', null, $c['owner']->id);

    $this->actingAs($c['owner']);
    enterPanelAs($c['vendor']);

    expect($ledger->outstandingFor($c['staff']->id, $c['vendor']->id))->toBe(7710.0)
        ->and($ledger->writtenOffTotal($c['vendor']->id))->toBe(5140.0);

    Livewire::test(StockLiabilityOverview::class)
        ->assertOk()
        ->assertSee('₦7,710.00')   // outstanding from staff
        ->assertSee('₦5,140.00');  // written off / unattributed
});
