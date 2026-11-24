<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Procurement\ProcurementReview;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new VendorPermissionsSeeder())->run();

    $this->owner  = User::factory()->create();
    $this->vendor = Vendor::create(['user_id' => $this->owner->id, 'name' => 'Approval Shop '.uniqid()]);
    VendorRoles::seedFor($this->vendor);
    $this->store = $this->vendor->defaultStore;

    $member = function (string $role): User {
        $user = User::factory()->create();
        $user->stores()->attach($this->store->id);
        $this->vendor->users()->syncWithoutDetaching([$user->id]);
        setPermissionsTeamId($this->vendor->id);
        $user->assignRole($role);

        return $user;
    };

    // Records purchases, may not approve them.
    Role::create(['name' => 'buyer', 'guard_name' => 'web', 'team_id' => $this->vendor->id])
        ->givePermissionTo('record_purchases');
    $this->buyer = $member('buyer');

    // inventory_manager holds approve_procurement.
    $this->manager = $member('inventory_manager');

    $this->supplier = Supplier::create(['vendor_id' => $this->vendor->id, 'name' => 'Dangote Depot']);

    $this->soap = Product::create([
        'vendor_id' => $this->vendor->id, 'store_id' => $this->store->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Bar Soap', 'barcode' => '1001', 'price' => 400, 'cost_price' => 280, 'status' => 'published',
    ]);
    ProductStoreStock::updateOrCreate(['product_id' => $this->soap->id, 'store_id' => $this->store->id], ['quantity' => 0, 'reserved' => 0]);

    $this->carton = ProductUnit::create([
        'product_id' => $this->soap->id, 'name' => 'Carton', 'conversion_factor' => 12,
        'price' => 4500, 'is_base' => false, 'is_active' => true,
    ]);

    $this->api = fn (string $path = '') => "/purchases/{$this->vendor->slug}/api{$path}";

    $this->purchase = fn (string $action, array $extra = []) => array_merge([
        'action'      => $action,
        'supplier_id' => $this->supplier->id,
        'lines'       => [[
            'product_id' => $this->soap->id, 'product_unit_id' => $this->carton->id,
            'quantity' => 2, 'unit_cost' => 3600, 'selling_price' => 450,
            'unit_prices' => [['unit_id' => $this->carton->id, 'price' => 5400]],
        ]],
    ], $extra);

    $this->submit = fn () => $this->actingAs($this->buyer)
        ->postJson(($this->api)('/purchases'), ($this->purchase)('submit'))
        ->assertOk()
        ->json('purchase');
});

test('the terminal tells each person whether they receive or submit', function () {
    $this->actingAs($this->buyer)->get("/purchases/{$this->vendor->slug}")->assertOk()->assertSee('"canApprove":false', false);
    $this->actingAs($this->manager)->get("/purchases/{$this->vendor->slug}")->assertOk()->assertSee('"canApprove":true', false);
    $this->actingAs($this->owner)->get("/purchases/{$this->vendor->slug}")->assertOk()->assertSee('"canApprove":true', false);
});

test('someone who cannot approve cannot receive stock directly', function () {
    $this->actingAs($this->buyer)
        ->postJson(($this->api)('/purchases'), ($this->purchase)('receive', ['payment' => ['option' => 'none']]))
        ->assertForbidden();

    expect(Procurement::count())->toBe(0)
        ->and((int) $this->soap->fresh()->stock_quantity)->toBe(0);
});

test('a submitted purchase waits for approval and moves nothing', function () {
    $p = ($this->submit)();

    expect($p['status'])->toBe('pending')
        ->and($p['status_label'])->toBe('Awaiting Approval')
        ->and($p['awaiting_approval'])->toBeTrue()
        ->and($p['can_receive'])->toBeFalse()
        ->and($p['can_resume'])->toBeFalse()
        ->and($p['can_pay'])->toBeFalse();

    $soap = $this->soap->fresh();
    expect((int) $soap->stock_quantity)->toBe(0)
        ->and((float) $soap->cost_price)->toBe(280.0)
        ->and((float) $soap->price)->toBe(400.0)
        ->and((float) $this->carton->fresh()->price)->toBe(4500.0);

    // The buyer cannot receive it either way.
    $this->actingAs($this->buyer)
        ->postJson(($this->api)("/purchases/{$p['id']}/receive"), ['payment' => ['option' => 'none']])
        ->assertForbidden();
});

test('an approver receives a submitted purchase, choosing the payment', function () {
    $p = ($this->submit)();

    $this->actingAs($this->manager)
        ->getJson(($this->api)("/purchases/{$p['id']}"))
        ->assertJsonPath('purchase.can_receive', true)
        ->assertJsonPath('purchase.can_resume', true);

    // Never without a payment choice.
    $this->postJson(($this->api)("/purchases/{$p['id']}/receive"), [])
        ->assertJsonValidationErrors('payment');

    $this->postJson(($this->api)("/purchases/{$p['id']}/receive"), ['payment' => ['option' => 'part', 'amount' => 2000, 'method' => 'cash']])
        ->assertOk()
        ->assertJsonPath('purchase.status_label', 'Received')
        ->assertJsonPath('purchase.received_by', $this->manager->name)
        ->assertJsonPath('purchase.recorded_by', $this->buyer->name)
        ->assertJsonPath('purchase.paid', 2000)
        ->assertJsonPath('purchase.balance', 5200);

    $soap = $this->soap->fresh();
    expect((int) $soap->stock_quantity)->toBe(24)
        ->and((float) $soap->cost_price)->toBe(300.0)
        ->and((float) $soap->price)->toBe(450.0)
        ->and((float) $this->carton->fresh()->price)->toBe(5400.0);

    // Once only.
    $this->postJson(($this->api)("/purchases/{$p['id']}/receive"), ['payment' => ['option' => 'none']])
        ->assertJsonValidationErrors('id');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(24);
});

test('an approver can correct a submission in the terminal and receive it', function () {
    $p = ($this->submit)();

    $this->actingAs($this->manager)
        ->postJson(($this->api)('/purchases'), ($this->purchase)('receive', [
            'id'      => $p['id'],
            'payment' => ['option' => 'none'],
            'lines'   => [['product_id' => $this->soap->id, 'quantity' => 10, 'unit_cost' => 310, 'selling_price' => 450]],
        ]))
        ->assertOk()
        ->assertJsonPath('purchase.id', $p['id'])
        ->assertJsonPath('purchase.status', 'approved');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(10)
        ->and(Procurement::count())->toBe(1);
});

test('the person who submitted cannot reopen it', function () {
    $p = ($this->submit)();

    $this->actingAs($this->buyer)
        ->postJson(($this->api)('/purchases'), ($this->purchase)('submit', ['id' => $p['id']]))
        ->assertJsonValidationErrors('id');
});

test('the old two-person review refuses a terminal submission, and the till does not list it', function () {
    $p = ($this->submit)();

    expect(fn () => app(ProcurementReview::class)->approve(Procurement::find($p['id']), $this->manager, $this->store->id))
        ->toThrow(RuntimeException::class, 'Purchase History');

    expect((int) $this->soap->fresh()->stock_quantity)->toBe(0)
        ->and(Procurement::whereNull('submitted_at')->whereKey($p['id'])->exists())->toBeFalse();
});

test('submitting holds serials to the same standard as receiving', function () {
    $this->soap->update(['track_serial' => true]);

    $this->actingAs($this->buyer)
        ->postJson(($this->api)('/purchases'), ($this->purchase)('submit'))
        ->assertJsonValidationErrors('lines.0.serials');
});
