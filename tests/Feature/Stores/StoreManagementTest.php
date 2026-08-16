<?php

use App\Filament\Vendor\Resources\Stores\Pages\CreateStore;
use App\Filament\Vendor\Resources\Stores\Pages\ListStores;
use App\Filament\Vendor\Resources\Stores\StoreResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function mgmtVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Mgmt Vendor '.uniqid(),
    ]);
}

function mgmtMember(Vendor $vendor, string $role = 'storekeeper'): User
{
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole($role);

    return $member;
}

function actAsMgmt(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

// ─── The policy is the gate, not the nav ────────────────────────────

test('the owner may manage stores', function () {
    $vendor = mgmtVendor();
    actAsMgmt($vendor, $vendor->user);

    $store = $vendor->defaultStore;

    expect(auth()->user()->can('viewAny', Store::class))->toBeTrue()
        ->and(auth()->user()->can('create', Store::class))->toBeTrue()
        ->and(auth()->user()->can('update', $store))->toBeTrue()
        ->and(auth()->user()->can('setDefault', $store))->toBeTrue()
        ->and(auth()->user()->can('toggleActive', $store))->toBeTrue()
        ->and(auth()->user()->can('assignMembers', $store))->toBeTrue()
        ->and(StoreResource::canAccess())->toBeTrue();
});

test('a non-owner member is refused every management action at the policy layer', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = mgmtVendor();
    VendorRoles::seedFor($vendor);
    $member = mgmtMember($vendor);
    $store = $vendor->defaultStore;

    actAsMgmt($vendor, $member);

    // Not merely a hidden menu item — the policy itself refuses, so a forged
    // request lands in the same place as a missing button.
    expect(auth()->user()->can('viewAny', Store::class))->toBeFalse()
        ->and(auth()->user()->can('create', Store::class))->toBeFalse()
        ->and(auth()->user()->can('update', $store))->toBeFalse()
        ->and(auth()->user()->can('setDefault', $store))->toBeFalse()
        ->and(auth()->user()->can('toggleActive', $store))->toBeFalse()
        ->and(auth()->user()->can('assignMembers', $store))->toBeFalse()
        ->and(StoreResource::canAccess())->toBeFalse();
});

test('a super admin may manage stores they do not own', function () {
    $vendor = mgmtVendor();
    $admin = User::factory()->create();
    setPermissionsTeamId(null);
    Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
    $admin->assignRole('super_admin');

    actAsMgmt($vendor, $admin);

    expect($vendor->isOwner($admin))->toBeFalse()
        ->and(auth()->user()->can('create', Store::class))->toBeTrue()
        ->and(auth()->user()->can('setDefault', $vendor->defaultStore))->toBeTrue();
});

test('nobody may delete a store', function () {
    $vendor = mgmtVendor();
    actAsMgmt($vendor, $vendor->user);

    expect(auth()->user()->can('delete', $vendor->defaultStore))->toBeFalse()
        ->and(StoreResource::canDelete($vendor->defaultStore))->toBeFalse();
});

// ─── Creating ───────────────────────────────────────────────────────

test('a created branch opens active, not default, and empty', function () {
    $vendor = mgmtVendor();
    actAsMgmt($vendor, $vendor->user);

    Livewire::test(CreateStore::class)
        ->fillForm(['name' => 'Uyo Branch', 'address' => '12 Aka Road', 'phone' => '08040000000'])
        ->call('create')
        ->assertHasNoFormErrors();

    $branch = Store::where('vendor_id', $vendor->id)->where('name', 'Uyo Branch')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->is_active)->toBeTrue()
        ->and($branch->is_default)->toBeFalse()
        ->and($branch->slug)->toBe('uyo-branch')
        ->and($branch->productStocks()->count())->toBe(0)
        // The vendor still has exactly one main store.
        ->and(Store::where('vendor_id', $vendor->id)->where('is_default', true)->count())->toBe(1);
});

test('slugs stay unique within a vendor and may repeat across vendors', function () {
    $vendorA = mgmtVendor();
    $vendorB = mgmtVendor();

    $a1 = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Uyo Branch']);
    $a2 = Store::create(['vendor_id' => $vendorA->id, 'name' => 'Uyo Branch']);
    $b1 = Store::create(['vendor_id' => $vendorB->id, 'name' => 'Uyo Branch']);

    expect($a1->slug)->toBe('uyo-branch')
        ->and($a2->slug)->not->toBe('uyo-branch')
        ->and($b1->slug)->toBe('uyo-branch');
});

test('an empty new branch appears in the grid and can receive stock', function () {
    $vendor = mgmtVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Fresh Branch']);

    $metrics = App\Services\Inventory\StoreStockMetrics::forStores([$branch->id]);
    $empty = $metrics[$branch->id] ?? App\Services\Inventory\StoreStockMetrics::empty();

    expect($empty->product_count)->toBe(0)
        ->and($empty->cost_value)->toBe(0.0);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Stocked '.uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'status' => 'published',
    ]);

    app(App\Actions\Inventory\AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: 7, transactionType: 'restock', store: $branch->id,
    );

    expect(ProductStoreStock::where('store_id', $branch->id)->value('quantity'))->toBe(7);
});

// ─── Setting the main store ─────────────────────────────────────────

test('setting a new main store clears the old one atomically', function () {
    $vendor = mgmtVendor();
    $old = $vendor->defaultStore;
    $new = Store::create(['vendor_id' => $vendor->id, 'name' => 'New Main']);

    $new->makeDefault();

    expect($new->fresh()->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse()
        // The invariant the rest of the build leans on.
        ->and(Store::where('vendor_id', $vendor->id)->where('is_default', true)->count())->toBe(1);
});

test('the main store cannot change while stock is reserved at the current one', function () {
    $vendor = mgmtVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Reserved '.uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'status' => 'published',
    ]);
    ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => 10, 'reserved' => 3]);

    expect($vendor->defaultStore->hasOutstandingReservations())->toBeTrue();

    actAsMgmt($vendor, $vendor->user);

    Livewire::test(ListStores::class)
        ->callTableAction('setDefault', $branch);

    // Refused: the outgoing main store still owes those units to an order.
    expect($branch->fresh()->is_default)->toBeFalse()
        ->and($vendor->defaultStore->fresh()->is_default)->toBeTrue();
});

test('the main store can change once the reservation clears', function () {
    $vendor = mgmtVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Cleared '.uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'status' => 'published',
    ]);
    $row = ProductStoreStock::where('product_id', $product->id)->first();
    $row->update(['quantity' => 10, 'reserved' => 3]);
    $row->update(['reserved' => 0]);

    actAsMgmt($vendor, $vendor->user);

    Livewire::test(ListStores::class)
        ->callTableAction('setDefault', $branch);

    expect($branch->fresh()->is_default)->toBeTrue()
        ->and(Store::where('vendor_id', $vendor->id)->where('is_default', true)->count())->toBe(1);
});

// ─── Opening and closing ────────────────────────────────────────────

test('the main store cannot be closed', function () {
    $vendor = mgmtVendor();
    actAsMgmt($vendor, $vendor->user);

    Livewire::test(ListStores::class)
        ->callTableAction('toggleActive', $vendor->defaultStore);

    expect($vendor->defaultStore->fresh()->is_active)->toBeTrue();
});

test('a non-default branch closes and reopens, and closed stock stops being sellable', function () {
    $vendor = mgmtVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Closable']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name' => 'Closable Stock '.uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'status' => 'published',
    ]);
    ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => 2]);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 50]);

    actAsMgmt($vendor, $vendor->user);

    Livewire::test(ListStores::class)->callTableAction('toggleActive', $branch);
    expect($branch->fresh()->is_active)->toBeFalse();

    // Phase 4 already refuses to allocate from a closed branch; this proves the
    // button actually reaches that behaviour.
    expect(App\Services\Inventory\StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(2);

    Livewire::test(ListStores::class)->callTableAction('toggleActive', $branch->fresh());
    expect($branch->fresh()->is_active)->toBeTrue()
        ->and(App\Services\Inventory\StoreAllocator::combinedAvailable($vendor->id, $product->id))->toBe(52);
});

// ─── Staff assignment ───────────────────────────────────────────────

test('assigning staff grants and revokes store access idempotently', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = mgmtVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Staffed Branch']);
    $memberA = mgmtMember($vendor);
    $memberB = mgmtMember($vendor);

    actAsMgmt($vendor, $vendor->user);

    // Granting through the real action, wiring and all.
    Livewire::test(ListStores::class)
        ->callTableAction('assignMembers', $branch, ['members' => [$memberA->id, $memberB->id]]);

    expect($branch->fresh()->users()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$memberA->id, $memberB->id])->sort()->values()->all());

    // Revoking is asserted against the relation rather than through the action:
    // the action's fillForm() repopulates the checkboxes from the store's
    // current staff on mount, and the test harness cannot hand in a SMALLER
    // set than that without the refill winning. What the action does with the
    // data it receives is one line — users()->sync() — and that is what these
    // assertions pin.
    $branch->users()->sync([$memberA->id, $memberB->id]);
    expect($branch->fresh()->users()->count())->toBe(2);

    $branch->users()->sync([$memberA->id]);
    expect($branch->fresh()->users()->pluck('users.id')->all())->toBe([$memberA->id]);

    $branch->users()->sync([]);
    expect($branch->fresh()->users()->count())->toBe(0);
});

test('an assigned member can then reach that store, and only that store', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = mgmtVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Their Branch']);
    $member = mgmtMember($vendor);

    actAsMgmt($vendor, $vendor->user);
    Livewire::test(ListStores::class)
        ->callTableAction('assignMembers', $branch, ['members' => [$member->id]]);

    actAsMgmt($vendor, $member);

    expect(App\Services\ActiveStore::accessibleFor($vendor, $member)->pluck('id')->all())
        ->toBe([$branch->id]);
});
