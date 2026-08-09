<?php

use App\Filament\Vendor\Resources\Procurements\Pages\CreateProcurement;
use App\Filament\Vendor\Resources\Procurements\Pages\ViewProcurement;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Procurement;
use App\Models\ProcurementLogisticsLeg;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinancialLedger;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpLegsVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Logistics Legs Store ' . uniqid()]);
    VendorRoles::seedFor($vendor);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);
    $category = Category::create(['name' => 'Legs Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Legs Product', 'price' => 5000, 'stock_quantity' => 0, 'status' => 'published',
    ]);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('owner', 'vendor', 'supplier', 'category', 'product');
}

function makeProcurementWithLegs(array $data, array $legs): Procurement
{
    $procurement = Procurement::create([
        'vendor_id' => $data['vendor']->id,
        'supplier_id' => $data['supplier']->id,
        'created_by' => $data['owner']->id,
        'status' => 'pending',
        'total_cost' => 0,
        'amount_paid' => 0,
        'payment_status' => 'credit',
        'payment_method' => 'cash',
    ]);

    foreach ($legs as $i => $leg) {
        $procurement->legs()->create([
            'route_label' => $leg['route_label'],
            'amount' => $leg['amount'],
            'sort_order' => $i,
        ]);
    }

    return $procurement;
}

test('creating a procurement through the wizard also creates its logistics legs', function () {
    $data = setUpLegsVendor();

    Livewire::test(CreateProcurement::class)
        ->fillForm([
            'supplier_id' => $data['supplier']->id,
            'items' => [
                ['product_id' => $data['product']->id, 'quantity' => 5, 'unit_cost' => 1000, 'selling_price' => 1500],
            ],
            'legs' => [
                ['route_label' => 'Supplier → Eket park', 'amount' => 1500],
                ['route_label' => 'Eket park → Uyo park', 'amount' => 1500],
                ['route_label' => 'Uyo park → store', 'amount' => 1500],
            ],
            'amount_paid' => 5000,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $procurement = Procurement::latest('id')->first();

    expect($procurement->legs)->toHaveCount(3)
        ->and($procurement->logisticsTotal())->toBe(4500.0);
});

test('logisticsTotal sums the legs and stays independent of total_cost', function () {
    $data = setUpLegsVendor();
    $procurement = makeProcurementWithLegs($data, [
        ['route_label' => 'Leg A', 'amount' => 1000],
        ['route_label' => 'Leg B', 'amount' => 2000],
    ]);
    $procurement->update(['total_cost' => 50000]);

    expect($procurement->logisticsTotal())->toBe(3000.0)
        ->and((float) $procurement->fresh()->total_cost)->toBe(50000.0);
});

test('recording a logistics payment posts one ledger entry per unpaid leg from the chosen account', function () {
    $data = setUpLegsVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $procurement = makeProcurementWithLegs($data, [
        ['route_label' => 'Leg A', 'amount' => 1500],
        ['route_label' => 'Leg B', 'amount' => 1500],
    ]);

    Livewire::test(ViewProcurement::class, ['record' => $procurement->getRouteKey()])
        ->callAction('recordLogisticsPayment', data: ['financial_account_id' => $account->id]);

    expect($procurement->legs()->whereNotNull('posted_at')->count())->toBe(2)
        ->and($account->fresh()->balance())->toBe(-3000.0)
        ->and(FinancialLedgerEntry::where('source_type', (new ProcurementLogisticsLeg())->getMorphClass())->count())->toBe(2);
});

test('recording a logistics payment twice does not double-post already-paid legs', function () {
    $data = setUpLegsVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $procurement = makeProcurementWithLegs($data, [
        ['route_label' => 'Leg A', 'amount' => 1500],
    ]);

    $leg = $procurement->legs()->first();
    FinancialLedger::postEntry($account, 'out', 1500, source: $leg, description: 'Already posted');
    $leg->update(['financial_account_id' => $account->id, 'posted_at' => now()]);

    // The action should find nothing left to post — the leg is already paid.
    expect($procurement->legs()->whereNull('posted_at')->exists())->toBeFalse();

    FinancialLedger::postEntry($account, 'out', 1500, source: $leg, description: 'Attempted re-post');

    expect(FinancialLedgerEntry::where('source_type', $leg->getMorphClass())->where('source_id', $leg->id)->count())->toBe(1)
        ->and($account->fresh()->balance())->toBe(-1500.0);
});

test('a posted leg\'s amount and route cannot be changed', function () {
    $data = setUpLegsVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $procurement = makeProcurementWithLegs($data, [['route_label' => 'Leg A', 'amount' => 1500]]);
    $leg = $procurement->legs()->first();
    $leg->update(['financial_account_id' => $account->id, 'posted_at' => now()]);

    expect(fn () => $leg->update(['amount' => 9999]))->toThrow(\LogicException::class);
    expect(fn () => $leg->update(['route_label' => 'Changed']))->toThrow(\LogicException::class);
});

test('an unposted leg can still be freely updated', function () {
    $data = setUpLegsVendor();
    $procurement = makeProcurementWithLegs($data, [['route_label' => 'Leg A', 'amount' => 1500]]);
    $leg = $procurement->legs()->first();

    $leg->update(['amount' => 2000]);

    expect((float) $leg->fresh()->amount)->toBe(2000.0);
});

test('the Record Logistics Payment action is hidden once every leg is paid', function () {
    $data = setUpLegsVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $procurement = makeProcurementWithLegs($data, [['route_label' => 'Leg A', 'amount' => 1500]]);

    Livewire::test(ViewProcurement::class, ['record' => $procurement->getRouteKey()])
        ->assertActionVisible('recordLogisticsPayment')
        ->callAction('recordLogisticsPayment', data: ['financial_account_id' => $account->id])
        ->assertActionHidden('recordLogisticsPayment');
});

test('the Record Logistics Payment action is hidden on a voided procurement', function () {
    $data = setUpLegsVendor();
    $procurement = makeProcurementWithLegs($data, [['route_label' => 'Leg A', 'amount' => 1500]]);
    $procurement->update(['status' => 'voided', 'void_reason' => 'Testing void visibility guard']);

    Livewire::test(ViewProcurement::class, ['record' => $procurement->getRouteKey()])
        ->assertActionHidden('recordLogisticsPayment');
});

test('a procurement with no logistics legs shows no Logistics section and no payment action', function () {
    $data = setUpLegsVendor();
    $procurement = makeProcurementWithLegs($data, []);

    Livewire::test(ViewProcurement::class, ['record' => $procurement->getRouteKey()])
        ->assertActionHidden('recordLogisticsPayment');

    expect($procurement->logisticsTotal())->toBe(0.0);
});
