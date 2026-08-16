<?php

use App\Filament\Vendor\Pages\StoreSelector;
use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function panelVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Panel Vendor '.uniqid(),
    ]);
}

function actAsInPanel(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

function panelProduct(Vendor $vendor, string $name, Store $store, int $quantity): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => $name,
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    // ProductObserver opened a row at the default store; put this product's
    // stock where the test wants it.
    if ($store->id === $vendor->defaultStore->id) {
        ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => $quantity]);
    } else {
        ProductStoreStock::where('product_id', $product->id)->delete();
        ProductStoreStock::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'quantity'   => $quantity,
        ]);
    }

    return $product->fresh();
}

// ─── The inventory list is store-scoped ─────────────────────────────

test('the product list shows only products held at the active store', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    $atMain   = panelProduct($vendor, 'Main Only', $vendor->defaultStore, 5);
    $atBranch = panelProduct($vendor, 'Branch Only', $branch, 7);

    actAsInPanel($vendor, $vendor->user);

    ActiveStore::set($vendor, $vendor->user, $vendor->defaultStore->id);
    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$atMain->id]);

    ActiveStore::set($vendor, $vendor->user, $branch->id);
    expect(ProductResource::getEloquentQuery()->pluck('id')->all())->toBe([$atBranch->id]);
});

test('the stock columns read the active store row, not the vendor mirror', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);

    $product = panelProduct($vendor, 'Shared', $vendor->defaultStore, 10);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 3, 'reserved' => 1]);

    // The vendor-wide mirror is the sum of both.
    expect($product->fresh()->stock_quantity)->toBe(13);

    actAsInPanel($vendor, $vendor->user);

    ActiveStore::set($vendor, $vendor->user, $branch->id);
    $row = ProductResource::getEloquentQuery()->find($product->id);

    expect((int) $row->store_quantity)->toBe(3)
        ->and((int) $row->store_reserved)->toBe(1);

    ActiveStore::set($vendor, $vendor->user, $vendor->defaultStore->id);
    $row = ProductResource::getEloquentQuery()->find($product->id);

    expect((int) $row->store_quantity)->toBe(10)
        ->and((int) $row->store_reserved)->toBe(0);
});

// ListProducts drives its own Blade view and pagination rather than Filament's
// Table component, so the page is asserted on its rendered output — the table
// helpers have no table to inspect.
test('the products list page renders only the active store products, with that store numbers', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    panelProduct($vendor, 'Main Widget', $vendor->defaultStore, 4);
    panelProduct($vendor, 'Branch Widget', $branch, 9);

    actAsInPanel($vendor, $vendor->user);
    ActiveStore::set($vendor, $vendor->user, $branch->id);

    Livewire::test(ListProducts::class)
        ->assertSee('Branch Widget')
        ->assertDontSee('Main Widget')
        // 9 at this store, not the vendor-wide total.
        ->assertSee('9 available');
});

test('the same product reports each store own numbers on the list', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    // Both well clear of the low-stock threshold, so the label is the plain
    // "N available" form and the assertion is about the number, not the badge.
    $product = panelProduct($vendor, 'Shared Widget', $vendor->defaultStore, 12);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 30]);

    expect($product->fresh()->stock_quantity)->toBe(42);

    actAsInPanel($vendor, $vendor->user);

    ActiveStore::set($vendor, $vendor->user, $vendor->defaultStore->id);
    Livewire::test(ListProducts::class)->assertSee('12 available');

    ActiveStore::set($vendor, $vendor->user, $branch->id);
    Livewire::test(ListProducts::class)->assertSee('30 available');
});

// ─── The selector grid ──────────────────────────────────────────────

test('the grid shows the cost value to an owner', function () {
    $vendor = panelVendor();
    panelProduct($vendor, 'Costed', $vendor->defaultStore, 10);

    actAsInPanel($vendor, $vendor->user);

    Livewire::test(StoreSelector::class)
        ->assertSee('Main Store')
        ->assertSee('Value (cost)');
});

test('the grid hides the cost value from a member without view_cost_price', function () {
    // Permissions must exist before roles are seeded — VendorRoles::seedFor
    // attaches whatever Permission rows it finds, so seeding it after would
    // give the storekeeper a role carrying nothing. Same order the repo's
    // other permission tests use.
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = panelVendor();
    panelProduct($vendor, 'Costed', $vendor->defaultStore, 10);

    VendorRoles::seedFor($vendor);
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    $member->stores()->attach($vendor->defaultStore->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('storekeeper');

    actAsInPanel($vendor, $member);

    Livewire::test(StoreSelector::class)
        ->assertSee('Value (retail)')
        ->assertDontSee('Value (cost)');
});

test('selecting a store from the grid switches the active store', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    actAsInPanel($vendor, $vendor->user);

    Livewire::test(StoreSelector::class)->call('selectStore', $branch->id);

    expect(ActiveStore::get($vendor, $vendor->user)->id)->toBe($branch->id);
});

test('the grid refuses a store the user may not reach', function () {
    $vendorA = panelVendor();
    $vendorB = panelVendor();

    actAsInPanel($vendorA, $vendorA->user);

    Livewire::test(StoreSelector::class)->call('selectStore', $vendorB->defaultStore->id);

    expect(ActiveStore::get($vendorA, $vendorA->user)->id)->toBe($vendorA->defaultStore->id);
});

// ─── The stock actions receive the active store ─────────────────────

test('a panel stock adjustment lands on the active store, not the default', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch']);
    $product = panelProduct($vendor, 'Adjusted', $vendor->defaultStore, 10);
    ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $branch->id, 'quantity' => 0]);

    actAsInPanel($vendor, $vendor->user);
    ActiveStore::set($vendor, $vendor->user, $branch->id);

    app(App\Actions\Inventory\AdjustStockAction::class)->execute(
        productId: $product->id,
        quantityChanged: 6,
        transactionType: 'restock',
        store: ActiveStore::currentId(),
    );

    $rows = ProductStoreStock::where('product_id', $product->id)->get()->keyBy('store_id');

    expect($rows[$branch->id]->quantity)->toBe(6)
        ->and($rows[$vendor->defaultStore->id]->quantity)->toBe(10)
        ->and($product->fresh()->stock_quantity)->toBe(16)
        ->and(InventoryLedger::where('product_id', $product->id)->value('store_id'))->toBe($branch->id);
});

test('procurement approval receives the active store', function () {
    $vendor = panelVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Receiving Branch']);
    $product = panelProduct($vendor, 'Received', $vendor->defaultStore, 2);

    $supplier = App\Models\Supplier::create(['vendor_id' => $vendor->id, 'name' => 'S '.uniqid()]);
    $procurement = App\Models\Procurement::create([
        'vendor_id' => $vendor->id, 'supplier_id' => $supplier->id,
        'reference' => 'PO-'.uniqid(), 'status' => 'pending', 'created_by' => $vendor->user_id,
    ]);
    $procurement->items()->create([
        'product_id' => $product->id, 'quantity' => 5,
        'unit_cost' => 500, 'selling_price' => 1000,
    ]);

    app(App\Actions\Procurement\ApproveProcurementAction::class)->execute($procurement, $branch->id);

    $rows = ProductStoreStock::where('product_id', $product->id)->get()->keyBy('store_id');

    expect($rows[$branch->id]->quantity)->toBe(5)
        ->and($rows[$vendor->defaultStore->id]->quantity)->toBe(2);
});

test('outside the panel the actions still fall back to the default store', function () {
    // Checkout, POS and the order observer have no active store — Phase 2's
    // fallback must remain intact for them.
    $vendor = panelVendor();
    $product = panelProduct($vendor, 'Fallback', $vendor->defaultStore, 10);

    expect(ActiveStore::currentId())->toBeNull();

    app(App\Actions\Inventory\AdjustStockAction::class)->execute(
        productId: $product->id, quantityChanged: 3, transactionType: 'restock',
    );

    expect(ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $vendor->defaultStore->id)->value('quantity'))->toBe(13);
});
