<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\ProcurementLogisticsLeg;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bootProcurementWizardVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Transport Cost Store']);
    VendorRoles::seedFor($vendor);

    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);
    $product = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat'])->id,
        'name'        => 'Wired Earbuds',
        'price'       => 5000,
        'cost_price'  => 3000,
        'status'      => 'published',
    ]);

    return [$owner, $vendor, $supplier, $product];
}

test('the procurement wizard records transport stages as logistics legs on submit', function () {
    [$owner, $vendor, $supplier, $product] = bootProcurementWizardVendor();

    $this->actingAs($owner);

    $this->post(route('procurement.storeSupplier'), ['supplier_id' => $supplier->id, 'store_id' => $vendor->defaultStore->id])
        ->assertRedirect(route('procurement.items'));

    $this->post(route('procurement.storeItems'), [
        'items' => [
            ['product_id' => $product->id, 'barcode' => '', 'quantity' => 2, 'unit_cost' => 3000, 'selling_price' => 5000],
        ],
    ])->assertRedirect(route('procurement.logistics'));

    $this->get(route('procurement.logistics'))->assertOk()->assertSee('Transport Cost');

    $this->post(route('procurement.storeLogistics'), [
        'legs' => [
            ['route_label' => 'Supplier → Eket park', 'amount' => 1500],
            ['route_label' => 'Eket park → Store', 'amount' => 800],
        ],
    ])->assertRedirect(route('procurement.financials'));

    $this->get(route('procurement.financials'))->assertOk()->assertSee('2,300.00');

    $this->post(route('procurement.storeFinancials'), [
        'payment_method'   => 'bank_transfer',
        'amount_paid'      => 6000,
        'reference_number' => '',
    ])->assertRedirect(route('procurement.confirm'));

    $this->get(route('procurement.confirm'))->assertOk()->assertSee('2,300.00');

    $this->post(route('procurement.submit'))
        ->assertRedirect(route('procurement.create'));

    $procurement = Procurement::where('vendor_id', $vendor->id)->firstOrFail();

    expect($procurement->total_cost)->toEqual('6000.00')
        ->and($procurement->legs()->count())->toBe(2)
        ->and($procurement->logisticsTotal())->toBe(2300.0);

    $legs = $procurement->legs()->orderBy('sort_order')->get();
    expect($legs[0]->route_label)->toBe('Supplier → Eket park')
        ->and((float) $legs[0]->amount)->toBe(1500.0)
        ->and($legs[0]->posted_at)->toBeNull()
        ->and($legs[1]->route_label)->toBe('Eket park → Store');
});

test('the procurement wizard submits fine when the transport-cost step is skipped entirely', function () {
    [$owner, $vendor, $supplier, $product] = bootProcurementWizardVendor();

    $this->actingAs($owner);

    $this->post(route('procurement.storeSupplier'), ['supplier_id' => $supplier->id, 'store_id' => $vendor->defaultStore->id]);
    $this->post(route('procurement.storeItems'), [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 3000, 'selling_price' => 5000],
        ],
    ]);

    // Vendor jumps straight from Items to Financials without visiting Logistics.
    $this->post(route('procurement.storeFinancials'), [
        'payment_method' => 'cash',
        'amount_paid'    => 0,
    ])->assertRedirect(route('procurement.confirm'));

    $this->post(route('procurement.submit'))->assertRedirect(route('procurement.create'));

    $procurement = Procurement::where('vendor_id', $vendor->id)->firstOrFail();

    expect($procurement->legs()->count())->toBe(0)
        ->and($procurement->logisticsTotal())->toBe(0.0);
});

test('a transport stage requires both a label and a cost', function () {
    [$owner, $vendor, $supplier, $product] = bootProcurementWizardVendor();

    $this->actingAs($owner);
    $this->post(route('procurement.storeSupplier'), ['supplier_id' => $supplier->id, 'store_id' => $vendor->defaultStore->id]);
    $this->post(route('procurement.storeItems'), [
        'items' => [
            ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 3000, 'selling_price' => 5000],
        ],
    ]);

    $this->post(route('procurement.storeLogistics'), [
        'legs' => [
            ['route_label' => '', 'amount' => 1500],
        ],
    ])->assertSessionHasErrors('legs.0.route_label');
});
