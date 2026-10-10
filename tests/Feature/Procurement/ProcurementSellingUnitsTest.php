<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Category;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Buying in a bigger unit: "5 Cartons of 48 at ₦10,000 each".

function unitProcurementContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Noodle Store', 'allow_negative_stock' => true]);
    VendorRoles::seedFor($vendor);

    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Distributor']);
    $product  = Product::create([
        'vendor_id'   => $vendor->id,
        'category_id' => Category::create(['name' => 'Food'])->id,
        'name'        => 'Indomie',
        'base_unit'   => 'Pack',
        'price'       => 250,
        'cost_price'  => 200,
        'status'      => 'published',
    ]);

    $carton = ProductUnit::create([
        'product_id' => $product->id, 'name' => 'Carton', 'conversion_factor' => 48, 'price' => 11500,
    ]);

    return compact('owner', 'vendor', 'supplier', 'product', 'carton');
}

function procureThroughWizard(array $ctx, array $item): Procurement
{
    test()->actingAs($ctx['owner']);

    test()->post(route('procurement.storeSupplier'), ['supplier_id' => $ctx['supplier']->id, 'store_id' => $ctx['vendor']->defaultStore->id]);
    test()->post(route('procurement.storeItems'), ['items' => [$item]])->assertRedirect(route('procurement.logistics'));
    test()->post(route('procurement.storeFinancials'), ['payment_method' => 'cash', 'amount_paid' => 0, 'reference_number' => '']);
    test()->get(route('procurement.confirm'))->assertOk();
    test()->post(route('procurement.submit'))->assertRedirect();

    return Procurement::where('vendor_id', $ctx['vendor']->id)->latest('id')->firstOrFail();
}

test('cartons are recorded and received as packs, with cost per pack', function () {
    $ctx = unitProcurementContext();

    $procurement = procureThroughWizard($ctx, [
        'product_id' => $ctx['product']->id, 'barcode' => '', 'product_unit_id' => $ctx['carton']->id,
        'quantity' => 5, 'unit_cost' => 10000, 'selling_price' => 250,
    ]);

    $line = $procurement->items()->firstOrFail();

    expect((float) $procurement->total_cost)->toBe(50000.0)
        ->and((int) $line->quantity)->toBe(240)
        ->and((float) $line->unit_cost)->toBe(208.33)
        ->and((int) $line->unit_qty)->toBe(5)
        ->and((float) $line->cost_per_unit)->toBe(10000.0)
        ->and($line->product_unit_id)->toBe($ctx['carton']->id);

    app(ApproveProcurementAction::class)->execute($procurement);

    $product = $ctx['product']->fresh();

    expect($product->stock_quantity)->toBe(240)
        ->and((float) $product->cost_price)->toBe(208.33);
});

test('a delivery settles stock that was sold below zero', function () {
    $ctx = unitProcurementContext();

    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id,
        quantityChanged: -8,
        transactionType: 'pos_sale',
        store: $ctx['vendor']->defaultStore->id,
        allowNegative: true,
    );

    expect($ctx['product']->fresh()->stock_quantity)->toBe(-8);

    $procurement = procureThroughWizard($ctx, [
        'product_id' => $ctx['product']->id, 'barcode' => '', 'quantity' => 30, 'unit_cost' => 200, 'selling_price' => 250,
    ]);

    app(ApproveProcurementAction::class)->execute($procurement);

    expect($ctx['product']->fresh()->stock_quantity)->toBe(22);
});

test('a unit from another product is refused', function () {
    $ctx = unitProcurementContext();
    $other = Product::create([
        'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['product']->category_id,
        'name' => 'Golden Penny', 'price' => 300, 'status' => 'published',
    ]);

    $this->actingAs($ctx['owner']);
    $this->post(route('procurement.storeSupplier'), ['supplier_id' => $ctx['supplier']->id, 'store_id' => $ctx['vendor']->defaultStore->id]);

    $this->post(route('procurement.storeItems'), ['items' => [[
        'product_id' => $other->id, 'product_unit_id' => $ctx['carton']->id,
        'quantity' => 1, 'unit_cost' => 10000, 'selling_price' => 300,
    ]]])->assertSessionHasErrors('items.0.product_unit_id');
});
