<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new VendorPermissionsSeeder())->run();

    $this->owner  = User::factory()->create();
    $this->vendor = Vendor::create(['user_id' => $this->owner->id, 'name' => 'Pay Shop '.uniqid()]);
    VendorRoles::seedFor($this->vendor);
    $this->store = $this->vendor->defaultStore;

    $this->supplier = Supplier::create(['vendor_id' => $this->vendor->id, 'name' => 'Dangote Depot']);

    $this->soap = Product::create([
        'vendor_id'   => $this->vendor->id,
        'store_id'    => $this->store->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'        => 'Bar Soap',
        'sku'         => 'SOAP-1',
        'barcode'     => '1001',
        'price'       => 400,
        'cost_price'  => 280,
        'status'      => 'published',
    ]);
    ProductStoreStock::updateOrCreate(['product_id' => $this->soap->id, 'store_id' => $this->store->id], ['quantity' => 5, 'reserved' => 0]);

    $this->carton = ProductUnit::create([
        'product_id' => $this->soap->id, 'name' => 'Carton', 'conversion_factor' => 12,
        'price' => 4500, 'is_base' => false, 'is_active' => true,
    ]);
    ProductBarcode::create(['product_id' => $this->soap->id, 'product_unit_id' => $this->carton->id, 'barcode' => '1001-CTN']);

    $this->api = fn (string $path = '') => "/purchases/{$this->vendor->slug}/api{$path}";

    // One carton at ₦3,600: a ₦3,600 purchase.
    $this->receive = fn (array $payment, array $extra = []) => $this->postJson(($this->api)('/purchases'), [
        'action'      => 'receive',
        'supplier_id' => $this->supplier->id,
        'payment'     => $payment,
        'lines'       => [array_merge([
            'product_id' => $this->soap->id, 'product_unit_id' => $this->carton->id,
            'quantity' => 1, 'unit_cost' => 3600, 'selling_price' => 400,
        ], $extra)],
    ]);

    $this->actingAs($this->owner);
});

test('stock cannot be received without choosing how it was paid', function () {
    ($this->receive)([])->assertUnprocessable()->assertJsonValidationErrors('payment.option');

    expect(Procurement::count())->toBe(0)
        ->and((int) $this->soap->fresh()->stock_quantity)->toBe(5);
});

test('paid in full records one payment for the total', function () {
    ($this->receive)(['option' => 'full', 'method' => 'transfer'])
        ->assertOk()
        ->assertJsonPath('purchase.paid', 3600)
        ->assertJsonPath('purchase.balance', 0)
        ->assertJsonPath('purchase.payment_label', 'Paid')
        ->assertJsonPath('purchase.payments.0.amount', 3600)
        ->assertJsonPath('purchase.payments.0.method_label', 'Transfer')
        ->assertJsonPath('purchase.can_pay', false);

    expect(Procurement::first()->payment_method)->toBe('bank_transfer');
});

test('not paid records no payment and leaves the whole total owed', function () {
    ($this->receive)(['option' => 'none'])
        ->assertOk()
        ->assertJsonPath('purchase.paid', 0)
        ->assertJsonPath('purchase.balance', 3600)
        ->assertJsonPath('purchase.payment_label', 'Unpaid')
        ->assertJsonPath('purchase.can_pay', true);

    expect(PurchasePayment::count())->toBe(0);
});

test('a method is required when something was paid', function () {
    ($this->receive)(['option' => 'full'])->assertJsonValidationErrors('payment.method');
});

test('a supplier can be paid later, and the balance follows each payment', function () {
    $id = ($this->receive)(['option' => 'part', 'amount' => 1000, 'method' => 'cash'])
        ->assertJsonPath('purchase.payment_label', 'Part Paid')
        ->json('purchase.id');

    $this->postJson(($this->api)("/purchases/{$id}/payments"), ['amount' => 3000, 'method' => 'transfer'])
        ->assertJsonValidationErrors('amount');

    $this->postJson(($this->api)("/purchases/{$id}/payments"), ['amount' => 1500, 'method' => 'pos', 'note' => 'week 2'])
        ->assertOk()
        ->assertJsonPath('purchase.paid', 2500)
        ->assertJsonPath('purchase.balance', 1100)
        ->assertJsonPath('purchase.payment_label', 'Part Paid')
        ->assertJsonPath('purchase.payments.1.method_label', 'POS')
        ->assertJsonPath('purchase.payments.1.note', 'week 2');

    $this->postJson(($this->api)("/purchases/{$id}/payments"), ['amount' => 1100, 'method' => 'cash'])
        ->assertOk()
        ->assertJsonPath('purchase.balance', 0)
        ->assertJsonPath('purchase.payment_label', 'Paid')
        ->assertJsonPath('purchase.can_pay', false);

    expect(PurchasePayment::where('purchase_id', $id)->count())->toBe(3)
        ->and((float) Procurement::find($id)->amount_paid)->toBe(3600.0);

    // Nothing left to pay.
    $this->postJson(($this->api)("/purchases/{$id}/payments"), ['amount' => 1, 'method' => 'cash'])
        ->assertJsonValidationErrors('amount');
});

test('a draft cannot be paid', function () {
    $id = $this->postJson(($this->api)('/purchases'), [
        'action' => 'draft', 'supplier_id' => $this->supplier->id,
        'lines'  => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 300, 'selling_price' => 400]],
    ])->assertJsonPath('purchase.can_pay', false)->json('purchase.id');

    $this->postJson(($this->api)("/purchases/{$id}/payments"), ['amount' => 100, 'method' => 'cash'])
        ->assertJsonValidationErrors('amount');
});

test('the supplier list shows what is owed each supplier', function () {
    ($this->receive)(['option' => 'part', 'amount' => 1000, 'method' => 'cash']);
    ($this->receive)(['option' => 'none']);

    // A draft owes nothing.
    $this->postJson(($this->api)('/purchases'), [
        'action' => 'draft', 'supplier_id' => $this->supplier->id,
        'lines'  => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 9999, 'selling_price' => 400]],
    ]);

    Supplier::create(['vendor_id' => $this->vendor->id, 'name' => 'Paid Up Ltd']);

    $suppliers = collect($this->getJson(($this->api)('/suppliers'))->json('suppliers'))->keyBy('name');

    expect($suppliers['Dangote Depot']['balance'])->toEqual(6200)
        ->and($suppliers['Paid Up Ltd']['balance'])->toEqual(0);
});

test('history filters by payment status', function () {
    ($this->receive)(['option' => 'full', 'method' => 'cash']);
    ($this->receive)(['option' => 'none']);

    $this->getJson(($this->api)('/purchases?payment=credit'))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.payment_label', 'Unpaid');

    $this->getJson(($this->api)('/purchases?payment=full'))->assertJsonPath('meta.total', 1);
});

test('receiving saves the base cost and every unit\'s selling price', function () {
    ($this->receive)(['option' => 'none'], [
        'unit_cost'     => 3600,
        'selling_price' => 450,
        'unit_prices'   => [['unit_id' => $this->carton->id, 'price' => 5400]],
    ])->assertOk();

    $soap = $this->soap->fresh();

    expect((float) $soap->cost_price)->toBe(300.0)
        ->and((float) $soap->price)->toBe(450.0)
        ->and((float) $this->carton->fresh()->price)->toBe(5400.0);
});

test('a price for a unit of another product is refused', function () {
    $other = ProductUnit::create([
        'product_id' => Product::create([
            'vendor_id' => $this->vendor->id, 'category_id' => $this->soap->category_id,
            'name' => 'Other', 'price' => 10, 'status' => 'published',
        ])->id,
        'name' => 'Box', 'conversion_factor' => 5, 'price' => 40, 'is_base' => false, 'is_active' => true,
    ]);

    ($this->receive)(['option' => 'none'], ['unit_prices' => [['unit_id' => $other->id, 'price' => 1]]])
        ->assertJsonValidationErrors('lines.0.unit_prices');

    expect((float) $other->fresh()->price)->toBe(40.0);
});

test('the catalogue pages every receivable product with its unit barcodes and branch stock', function () {
    $first = $this->getJson(($this->api)('/catalogue?limit=500'))->assertOk()->json();

    expect($first)->toHaveCount(1)
        ->and($first[0])->toMatchArray(['id' => $this->soap->id, 'barcode' => '1001', 'sku' => 'SOAP-1', 'stock' => 5, 'cost_price' => 280.0])
        ->and(collect($first[0]['barcodes'])->firstWhere('barcode', '1001-CTN')['unit_id'])->toBe($this->carton->id)
        ->and(collect($first[0]['units'])->firstWhere('id', $this->carton->id))->toMatchArray(['factor' => 12, 'selling_price' => 4500.0]);

    $this->getJson(($this->api)('/catalogue?after_id='.$this->soap->id))->assertExactJson([]);
});

test('old purchases keep their paid and balance once moved onto payment rows', function () {
    $old = Procurement::create([
        'vendor_id' => $this->vendor->id, 'supplier_id' => $this->supplier->id, 'created_by' => $this->owner->id,
        'status' => Procurement::STATUS_APPROVED, 'approved_at' => now()->subWeek(),
        'total_cost' => 5000, 'amount_paid' => 2000, 'payment_status' => 'part_payment', 'payment_method' => 'bank_transfer',
    ]);
    $unpaid = Procurement::create([
        'vendor_id' => $this->vendor->id, 'supplier_id' => $this->supplier->id, 'created_by' => $this->owner->id,
        'status' => Procurement::STATUS_PENDING, 'total_cost' => 900, 'amount_paid' => 0, 'payment_status' => 'credit',
    ]);

    $backfill = require database_path('migrations/2026_10_14_100001_backfill_purchase_payments.php');
    $backfill->up();
    $backfill->up(); // idempotent

    expect(PurchasePayment::where('purchase_id', $old->id)->count())->toBe(1)
        ->and(PurchasePayment::where('purchase_id', $unpaid->id)->count())->toBe(0);

    $this->getJson(($this->api)("/purchases/{$old->id}"))
        ->assertJsonPath('purchase.paid', 2000)
        ->assertJsonPath('purchase.balance', 3000)
        ->assertJsonPath('purchase.payment_label', 'Part Paid')
        ->assertJsonPath('purchase.payments.0.method_label', 'Transfer');

    // And can then be paid off like any other.
    $this->postJson(($this->api)("/purchases/{$old->id}/payments"), ['amount' => 3000, 'method' => 'cash'])
        ->assertJsonPath('purchase.balance', 0)
        ->assertJsonPath('purchase.payment_label', 'Paid');
});
