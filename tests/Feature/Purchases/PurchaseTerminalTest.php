<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\ProcurementItemSerial;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new VendorPermissionsSeeder())->run();

    $this->owner  = User::factory()->create();
    $this->vendor = Vendor::create(['user_id' => $this->owner->id, 'name' => 'Purchase Shop '.uniqid()]);
    VendorRoles::seedFor($this->vendor);
    $this->store = $this->vendor->defaultStore;

    $this->supplier = Supplier::create(['vendor_id' => $this->vendor->id, 'name' => 'Dangote Depot']);

    $this->soap = Product::create([
        'vendor_id'   => $this->vendor->id,
        'store_id'    => $this->store->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'        => 'Bar Soap',
        'sku'         => 'SOAP-1',
        'barcode'     => '6151234500011',
        'price'       => 400,
        'cost_price'  => 280,
        'status'      => 'published',
    ]);
    ProductStoreStock::updateOrCreate(
        ['product_id' => $this->soap->id, 'store_id' => $this->store->id],
        ['quantity' => 0, 'reserved' => 0],
    );

    $this->carton = ProductUnit::create([
        'product_id'        => $this->soap->id,
        'name'              => 'Carton',
        'conversion_factor' => 12,
        'price'             => 4500,
        'is_base'           => false,
        'is_active'         => true,
    ]);
    ProductBarcode::create(['product_id' => $this->soap->id, 'product_unit_id' => $this->carton->id, 'barcode' => '6151234500099']);

    $this->api = fn (string $path = '') => "/purchases/{$this->vendor->slug}/api{$path}";

    $this->member = function (string $role): User {
        $user = User::factory()->create();
        $user->stores()->attach($this->store->id);
        $this->vendor->users()->syncWithoutDetaching([$user->id]);
        setPermissionsTeamId($this->vendor->id);
        $user->assignRole($role);

        return $user;
    };
});

test('the owner opens Purchases', function () {
    $this->actingAs($this->owner)
        ->get("/purchases/{$this->vendor->slug}")
        ->assertOk()
        ->assertSee('purchases-root', false);
});

test('a user without record_purchases cannot open Purchases or call its endpoints', function () {
    $keeper = ($this->member)('storekeeper');

    // Refused pages are redirected away rather than 403'd (bootstrap/app.php);
    // either way, the terminal never renders.
    $page = $this->actingAs($keeper)->get("/purchases/{$this->vendor->slug}");
    expect($page->status())->not->toBe(200);
    $page->assertDontSee('purchases-root', false);
    $this->actingAs($keeper)->getJson(($this->api)('/lookup?code=6151234500011'))->assertForbidden();
    $this->actingAs($keeper)->postJson(($this->api)('/purchases'), [])->assertForbidden();
    $this->actingAs($keeper)->getJson(($this->api)('/purchases'))->assertForbidden();
});

test('a role holding record_purchases can open Purchases', function () {
    $manager = ($this->member)('inventory_manager');

    $this->actingAs($manager)->get("/purchases/{$this->vendor->slug}")->assertOk();
});

test('someone from another shop is refused even with the permission elsewhere', function () {
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other '.uniqid()]);

    $this->actingAs(User::find($other->user_id))
        ->getJson(($this->api)('/lookup?code=6151234500011'))
        ->assertForbidden();
});

test('a scan finds the product by barcode, then by unit barcode, then by SKU', function () {
    $this->actingAs($this->owner);

    $this->getJson(($this->api)('/lookup?code=6151234500011'))
        ->assertJsonPath('found', true)
        ->assertJsonPath('product.id', $this->soap->id)
        ->assertJsonPath('product.scanned_unit_id', null);

    $this->getJson(($this->api)('/lookup?code=6151234500099'))
        ->assertJsonPath('product.scanned_unit_id', $this->carton->id);

    $this->getJson(($this->api)('/lookup?code=SOAP-1'))
        ->assertJsonPath('product.id', $this->soap->id);

    $this->getJson(($this->api)('/lookup?code=0000000000'))
        ->assertJsonPath('found', false);
});

test('another shop\'s barcode is not found', function () {
    $other = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other '.uniqid()]);
    Product::create([
        'vendor_id' => $other->id, 'category_id' => $this->soap->category_id,
        'name' => 'Theirs', 'barcode' => '999111', 'price' => 10, 'status' => 'published',
    ]);

    $this->actingAs($this->owner)
        ->getJson(($this->api)('/lookup?code=999111'))
        ->assertJsonPath('found', false);
});

test('receiving cartons adds base units to stock and sets cost per piece', function () {
    $this->actingAs($this->owner)->postJson(($this->api)('/purchases'), [
        'action'         => 'receive',
        'supplier_id'    => $this->supplier->id,
        'invoice_number' => 'INV-778',
        'purchased_on'   => now()->toDateString(),
        'payment'        => ['option' => 'part', 'amount' => 5000, 'method' => 'cash'],
        'lines'          => [[
            'product_id'      => $this->soap->id,
            'product_unit_id' => $this->carton->id,
            'quantity'        => 2,
            'unit_cost'       => 3600,
            'selling_price'   => 450,
        ]],
    ])->assertOk()
        ->assertJsonPath('purchase.status', 'approved')
        ->assertJsonPath('purchase.status_label', 'Received')
        ->assertJsonPath('purchase.total', 7200)
        ->assertJsonPath('purchase.balance', 2200)
        ->assertJsonPath('purchase.received_by', $this->owner->name)
        ->assertJsonPath('purchase.lines.0.base_qty', 24)
        ->assertJsonPath('purchase.lines.0.base_cost', 300);

    $soap = $this->soap->fresh();
    expect((float) $soap->cost_price)->toBe(300.0)
        ->and((float) $soap->price)->toBe(450.0)
        ->and((int) $soap->stock_quantity)->toBe(24)
        ->and(ProductStoreStock::where('product_id', $soap->id)->where('store_id', $this->store->id)->value('quantity'))->toBe(24);

    $purchase = Procurement::latest('id')->first();
    expect($purchase->approved_by)->toBe($this->owner->id)
        ->and($purchase->created_by)->toBe($this->owner->id)
        ->and($purchase->payment_status)->toBe('part_payment')
        ->and($purchase->invoice_number)->toBe('INV-778')
        ->and((float) $purchase->amount_paid)->toBe(5000.0);

    $this->assertDatabaseHas('inventory_ledgers', [
        'product_id' => $soap->id, 'transaction_type' => 'restock', 'quantity_change' => 24,
    ]);
});

test('a draft moves no stock and can be continued, then received', function () {
    $this->actingAs($this->owner);

    $draft = $this->postJson(($this->api)('/purchases'), [
        'action'      => 'draft',
        'supplier_id' => $this->supplier->id,
        'lines'       => [['product_id' => $this->soap->id, 'quantity' => 5, 'unit_cost' => 290, 'selling_price' => 400]],
    ])->assertOk()->assertJsonPath('purchase.status', 'draft')->json('purchase');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(0)
        ->and((float) $this->soap->fresh()->cost_price)->toBe(280.0);

    $this->postJson(($this->api)('/purchases'), [
        'action'      => 'receive',
        'payment'     => ['option' => 'none'],
        'id'          => $draft['id'],
        'supplier_id' => $this->supplier->id,
        'lines'       => [['product_id' => $this->soap->id, 'quantity' => 6, 'unit_cost' => 290, 'selling_price' => 400]],
    ])->assertOk()->assertJsonPath('purchase.id', $draft['id'])->assertJsonPath('purchase.status', 'approved');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(6)
        ->and(Procurement::count())->toBe(1);

    // Received is final.
    $this->postJson(($this->api)('/purchases'), [
        'action'      => 'draft',
        'id'          => $draft['id'],
        'supplier_id' => $this->supplier->id,
        'lines'       => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 1, 'selling_price' => 1]],
    ])->assertUnprocessable();

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(6);
});

test('a serial-tracked product needs one serial per base unit to be received', function () {
    $this->soap->update(['track_serial' => true]);
    $this->actingAs($this->owner);

    $payload = fn (array $serials) => [
        'action'      => 'receive',
        'payment'     => ['option' => 'none'],
        'supplier_id' => $this->supplier->id,
        'lines'       => [['product_id' => $this->soap->id, 'quantity' => 2, 'unit_cost' => 300, 'selling_price' => 400, 'serials' => $serials]],
    ];

    $this->postJson(($this->api)('/purchases'), $payload(['IMEI-1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.serials');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(0);

    $this->postJson(($this->api)('/purchases'), $payload(['IMEI-1', 'IMEI-2']))->assertOk();

    expect(ProcurementItemSerial::where('product_id', $this->soap->id)->pluck('serial')->all())->toBe(['IMEI-1', 'IMEI-2']);

    // The same serial cannot be received twice.
    $this->postJson(($this->api)('/purchases'), $payload(['IMEI-2', 'IMEI-3']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.serials');
});

test('an untracked product ignores serials', function () {
    $this->actingAs($this->owner)->postJson(($this->api)('/purchases'), [
        'action'      => 'receive',
        'payment'     => ['option' => 'none'],
        'supplier_id' => $this->supplier->id,
        'lines'       => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 300, 'selling_price' => 400, 'serials' => ['X']]],
    ])->assertOk();

    expect(ProcurementItemSerial::count())->toBe(0);
});

test('paid cannot exceed total, and a supplier from another shop is refused', function () {
    $this->actingAs($this->owner);
    $line = [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 300, 'selling_price' => 400]];

    // A part payment is less than the total.
    $this->postJson(($this->api)('/purchases'), ['action' => 'receive', 'supplier_id' => $this->supplier->id,
        'payment' => ['option' => 'part', 'amount' => 301, 'method' => 'cash'], 'lines' => $line])
        ->assertJsonValidationErrors('payment.amount');

    $foreign = Supplier::create(['vendor_id' => Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'X '.uniqid()])->id, 'name' => 'Not yours']);

    $this->postJson(($this->api)('/purchases'), ['action' => 'receive', 'supplier_id' => $foreign->id, 'payment' => ['option' => 'none'], 'lines' => $line])
        ->assertJsonValidationErrors('supplier_id');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(0);
});

test('an unknown barcode can be created as a product and is then found', function () {
    $this->actingAs($this->owner);

    $this->postJson(($this->api)('/products'), [
        'name' => 'Peak Milk Tin', 'barcode' => '5900000000017', 'base_unit' => 'Tin', 'price' => 950,
    ])->assertCreated()
        ->assertJsonPath('product.name', 'Peak Milk Tin')
        ->assertJsonPath('product.base_unit', 'Tin');

    $this->getJson(($this->api)('/lookup?code=5900000000017'))->assertJsonPath('found', true);

    // The same barcode cannot be used twice.
    $this->postJson(($this->api)('/products'), ['name' => 'Dupe', 'barcode' => '5900000000017', 'price' => 1])
        ->assertJsonValidationErrors('barcode');
});

test('a supplier can be added on the spot', function () {
    $this->actingAs($this->owner)
        ->postJson(($this->api)('/suppliers'), ['name' => 'Mama Nkechi'])
        ->assertCreated();

    expect(Supplier::where('vendor_id', $this->vendor->id)->where('name', 'Mama Nkechi')->exists())->toBeTrue();
});

test('history lists old procurements beside new purchases and filters them', function () {
    $old = Procurement::create([
        'vendor_id' => $this->vendor->id, 'store_id' => null, 'supplier_id' => $this->supplier->id,
        'created_by' => $this->owner->id, 'status' => Procurement::STATUS_PENDING,
        'total_cost' => 5000, 'amount_paid' => 0, 'payment_status' => 'credit',
    ]);
    $old->items()->create(['product_id' => $this->soap->id, 'quantity' => 10, 'unit_cost' => 500, 'selling_price' => 700, 'barcode' => 'IMEI-OLD']);

    $this->actingAs($this->owner)->postJson(($this->api)('/purchases'), [
        'action' => 'draft', 'supplier_id' => $this->supplier->id,
        'lines'  => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 300, 'selling_price' => 400]],
    ])->assertOk();

    $this->getJson(($this->api)('/purchases'))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson(($this->api)('/purchases?status=pending'))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reference', $old->fresh()->reference)
        ->assertJsonPath('data.0.status_label', 'Awaiting Check')
        ->assertJsonPath('data.0.balance', 5000);

    $this->getJson(($this->api)('/purchases?q=Bar Soap'))->assertJsonPath('meta.total', 2);

    $this->getJson(($this->api)("/purchases/{$old->id}"))
        ->assertOk()
        ->assertJsonPath('purchase.lines.0.base_qty', 10)
        ->assertJsonPath('purchase.lines.0.legacy_serial', 'IMEI-OLD');
});

test('drafts are left out of the daily procurement figures', function () {
    $this->actingAs($this->owner)->postJson(($this->api)('/purchases'), [
        'action' => 'draft', 'supplier_id' => $this->supplier->id,
        'lines'  => [['product_id' => $this->soap->id, 'quantity' => 1, 'unit_cost' => 300, 'selling_price' => 400]],
    ])->assertOk();

    expect(Procurement::whereNotIn('status', ['voided', 'draft'])->count())->toBe(0);
});

test('the deploy migration grants record_purchases to every role that runs procurement', function () {
    $custom = Role::create(['name' => 'buyer', 'guard_name' => 'web', 'team_id' => $this->vendor->id]);
    $custom->givePermissionTo('manage_procurement');

    $cashier = Role::create(['name' => 'till_only', 'guard_name' => 'web', 'team_id' => $this->vendor->id]);
    $cashier->givePermissionTo('access_pos');

    (require database_path('migrations/2026_10_12_110001_add_record_purchases_permission.php'))->up();

    expect($custom->fresh()->hasPermissionTo('record_purchases'))->toBeTrue()
        ->and($cashier->fresh()->hasPermissionTo('record_purchases'))->toBeFalse();
});

test('lookups and search report what is left at the branch, and it rises after receiving', function () {
    ProductStoreStock::where('product_id', $this->soap->id)->where('store_id', $this->store->id)->update(['quantity' => 7]);
    $this->actingAs($this->owner);

    $this->getJson(($this->api)('/lookup?code=6151234500011'))->assertJsonPath('product.stock', 7);
    $this->getJson(($this->api)('/products?q=Bar'))->assertJsonPath('products.0.stock', 7);

    $this->postJson(($this->api)('/purchases'), [
        'action' => 'receive', 'supplier_id' => $this->supplier->id, 'payment' => ['option' => 'none'],
        'lines'  => [['product_id' => $this->soap->id, 'product_unit_id' => $this->carton->id, 'quantity' => 1, 'unit_cost' => 3600, 'selling_price' => 400]],
    ])->assertOk();

    $this->getJson(($this->api)('/lookup?code=6151234500011'))->assertJsonPath('product.stock', 19);
});
