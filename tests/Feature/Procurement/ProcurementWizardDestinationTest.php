<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function wizardVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Wizard Vendor '.uniqid(),
    ]);
}

// Local rather than shared: helpers declared in a sibling test file only exist
// when that file happens to be loaded too, which makes running this one alone
// fail.
function wizardProduct(Vendor $vendor, string $name, Store $home, int $quantity = 0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name,
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    if ($quantity > 0) {
        ProductStoreStock::where('product_id', $product->id)
            ->where('store_id', $home->id)
            ->first()
            ->update(['quantity' => $quantity]);
    }

    return $product->fresh();
}

test('the first step will not continue without a destination branch', function () {
    $vendor = wizardVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    $this->actingAs(User::find($vendor->user_id))
        ->post(route('procurement.storeSupplier'), ['supplier_id' => $supplier->id])
        ->assertSessionHasErrors('store_id');
});

test('a branch the user cannot reach is refused', function () {
    $vendor = wizardVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    // Another vendor's branch entirely.
    $foreign = Store::create(['vendor_id' => wizardVendor()->id, 'name' => 'Someone Elses']);

    $this->actingAs(User::find($vendor->user_id))
        ->post(route('procurement.storeSupplier'), [
            'supplier_id' => $supplier->id,
            'store_id'    => $foreign->id,
        ])
        ->assertSessionHasErrors('store_id');

    expect(session('procurement.store_id'))->toBeNull();
});

test('choosing a branch carries it through to the saved order', function () {
    $vendor = wizardVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);
    $user = User::find($vendor->user_id);

    $this->actingAs($user)
        ->post(route('procurement.storeSupplier'), [
            'supplier_id' => $supplier->id,
            'store_id'    => $branch->id,
        ])
        ->assertRedirect(route('procurement.items'));

    expect(session('procurement.store_id'))->toBe($branch->id);

    $this->actingAs($user)
        ->withSession([
            'procurement.supplier_id' => $supplier->id,
            'procurement.store_id'    => $branch->id,
            'procurement.items'       => [],
            'procurement.financials'  => ['payment_method' => 'cash', 'amount_paid' => '0'],
        ])
        ->post(route('procurement.submit'))
        ->assertRedirect(route('procurement.create'));

    expect(Procurement::latest('id')->first()->store_id)->toBe($branch->id);
});

test('the items step sends you back when no branch has been chosen', function () {
    $vendor = wizardVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    $this->actingAs(User::find($vendor->user_id))
        ->withSession(['procurement.supplier_id' => $supplier->id])
        ->get(route('procurement.items'))
        ->assertRedirect(route('procurement.create'));
});

test('the branch chooser renders, listing every branch the user can reach', function () {
    $vendor = wizardVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $this->actingAs(User::find($vendor->user_id))
        ->get(route('procurement.create'))
        ->assertOk()
        ->assertSee('Deliver To')
        ->assertSee('Uyo Branch')
        ->assertSee($vendor->defaultStore->name);
});

test('the items step renders, naming the branch and blocking what it cannot receive', function () {
    $vendor = wizardVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);

    // Stocked at the default store, so this branch cannot receive it.
    $stocked = wizardProduct($vendor, 'JBL Speaker', $vendor->defaultStore, 6);
    $free    = wizardProduct($vendor, 'Empty Product', $vendor->defaultStore, 0);

    $response = $this->actingAs(User::find($vendor->user_id))
        ->withSession([
            'procurement.supplier_id' => $supplier->id,
            'procurement.store_id'    => $branch->id,
        ])
        ->get(route('procurement.items'))
        ->assertOk()
        ->assertSee('Uyo Branch');

    $json = $response->viewData('productsJson')->keyBy('name');

    expect($json['JBL Speaker']['receivable'])->toBeFalse()
        ->and($json['Empty Product']['receivable'])->toBeTrue();
});

test('the confirm step names the branch before the order is placed', function () {
    $vendor = wizardVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);
    $product = wizardProduct($vendor, 'Confirmed Item', $branch);

    $this->actingAs(User::find($vendor->user_id))
        ->withSession([
            'procurement.supplier_id' => $supplier->id,
            'procurement.store_id'    => $branch->id,
            'procurement.items'       => [[
                'product_id'    => $product->id,
                'barcode'       => null,
                'quantity'      => 2,
                'unit_cost'     => 500,
                'selling_price' => 1000,
            ]],
            // Same shape storeFinancials() puts there, reference_number included.
            'procurement.financials'  => [
                'payment_method'   => 'cash',
                'amount_paid'      => '0',
                'reference_number' => null,
            ],
        ])
        ->get(route('procurement.confirm'))
        ->assertOk()
        ->assertSee('Uyo Branch');
});
