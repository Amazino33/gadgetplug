<?php

use App\Filament\Vendor\Pages\StoreSelector;
use App\Filament\Vendor\Pages\StoresOverview;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
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

function overviewVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Overview Vendor '.uniqid(),
    ]);
}

function overviewProduct(Vendor $vendor, Store $store, int $qty, ?float $cost = 400.0, float $price = 1000.0): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Overview Product '.uniqid(),
        'price'          => $price,
        'cost_price'     => $cost,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    if ($store->id === $vendor->defaultStore->id) {
        ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => $qty]);
    } else {
        ProductStoreStock::where('product_id', $product->id)->delete();
        ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => $qty]);
    }

    return $product->fresh();
}

function overviewTillSale(Vendor $vendor, Store $store, Product $product, int $qty, float $unit): PosSale
{
    $sale = PosSale::create([
        'reference' => 'POS-'.strtoupper(uniqid()),
        'vendor_id' => $vendor->id,
        'store_id'  => $store->id,
        'cashier_id' => $vendor->user_id,
        'subtotal' => $qty * $unit, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => $qty * $unit,
        'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'unit_price' => $unit, 'quantity' => $qty, 'total' => $qty * $unit,
    ]);

    return $sale;
}

function actAsOverview(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

// ─── The roll-up aggregates per store ───────────────────────────────

test('the roll-up lists every store with its own valuation and sales', function () {
    $vendor = overviewVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Second Branch']);

    $atMain = overviewProduct($vendor, $vendor->defaultStore, 10);
    $atBranch = overviewProduct($vendor, $branch, 4);
    overviewTillSale($vendor, $branch, $atBranch, 2, 1000.0);

    actAsOverview($vendor, $vendor->user);

    $data = Livewire::test(StoresOverview::class)->instance()->comparison();
    $rows = collect($data['rows'])->keyBy(fn ($r) => $r['store']->id);

    expect($rows[$vendor->defaultStore->id]['retail_value'])->toBe(10000.0)
        ->and($rows[$vendor->defaultStore->id]['cost_value'])->toBe(4000.0)
        ->and($rows[$vendor->defaultStore->id]['sales_revenue'])->toBe(0.0)
        ->and($rows[$branch->id]['retail_value'])->toBe(4000.0)
        // Counter sales land on the branch that rang them up.
        ->and($rows[$branch->id]['sales_revenue'])->toBe(2000.0)
        ->and($data['totals']['retail_value'])->toBe(14000.0)
        ->and($data['totals']['sales_revenue'])->toBe(2000.0);
});

test('stores are ordered by the capital they carry', function () {
    $vendor = overviewVendor();
    $big = Store::create(['vendor_id' => $vendor->id, 'name' => 'Big Branch']);

    overviewProduct($vendor, $vendor->defaultStore, 1);
    overviewProduct($vendor, $big, 50);

    actAsOverview($vendor, $vendor->user);

    $rows = Livewire::test(StoresOverview::class)->instance()->comparison()['rows'];

    expect($rows[0]['store']->id)->toBe($big->id);
});

test('the roll-up renders for an owner with both value columns', function () {
    $vendor = overviewVendor();
    overviewProduct($vendor, $vendor->defaultStore, 10);

    actAsOverview($vendor, $vendor->user);

    Livewire::test(StoresOverview::class)
        ->assertSee('Main Store')
        ->assertSee('Value (retail)')
        ->assertSee('Value (cost)');
});

test('the roll-up names sales it could not attribute to any store', function () {
    $vendor = overviewVendor();
    $product = overviewProduct($vendor, $vendor->defaultStore, 10);

    // A recognised line with no allocation — it counts vendor-wide and for no
    // branch, which is the gap the page discloses.
    $order = App\Models\Order::create([
        'reference' => 'ORD-'.strtoupper(uniqid()),
        'customer_name' => 'B', 'customer_email' => 'b@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 3000, 'status' => 'delivered', 'payment_method' => 'pay_on_delivery',
    ]);
    App\Models\OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $vendor->id, 'quantity' => 3, 'unit_price' => 1000,
    ]);
    $order->forceFill(['revenue_recognized_at' => now()])->saveQuietly();

    actAsOverview($vendor, $vendor->user);

    expect(Livewire::test(StoresOverview::class)->instance()->unattributedSales())->toBe(3000.0);

    Livewire::test(StoresOverview::class)->assertSee('not attributed to any store');
});

// ─── Access boundaries ──────────────────────────────────────────────

test('an owner may open the roll-up', function () {
    $vendor = overviewVendor();
    actAsOverview($vendor, $vendor->user);

    expect(StoresOverview::canAccess())->toBeTrue();
});

test('a non-owner member may not open the roll-up at all', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = overviewVendor();
    VendorRoles::seedFor($vendor);

    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    $member->stores()->attach($vendor->defaultStore->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('storekeeper');

    actAsOverview($vendor, $member);

    // Gated at the page, so a storekeeper never sees another branch's capital.
    expect(StoresOverview::canAccess())->toBeFalse();
});

test('a super admin may open the roll-up', function () {
    $vendor = overviewVendor();
    $admin = User::factory()->create();

    // super_admin is a global role, not a vendor team one, so it is created
    // with no team context.
    setPermissionsTeamId(null);
    Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web');
    $admin->assignRole('super_admin');

    actAsOverview($vendor, $admin);

    expect($admin->fresh()->isSuperAdmin())->toBeTrue()
        // Not the vendor's owner, yet still sees every branch — the same
        // treatment ActiveStore gives them, since they are a member of nothing.
        ->and($vendor->isOwner($admin))->toBeFalse()
        ->and(StoresOverview::canAccess())->toBeTrue();
});

test('the roll-up only ever reports stores the viewer can access', function () {
    $vendorA = overviewVendor();
    $vendorB = overviewVendor();

    overviewProduct($vendorA, $vendorA->defaultStore, 5);
    overviewProduct($vendorB, $vendorB->defaultStore, 99);

    actAsOverview($vendorA, $vendorA->user);

    $rows = Livewire::test(StoresOverview::class)->instance()->comparison()['rows'];

    // Another vendor's branch is never a row here, whatever it holds.
    expect(collect($rows)->pluck('store.id')->all())->toBe([$vendorA->defaultStore->id]);
});

// ─── The store cards carry per-branch sales ─────────────────────────

test('a store card shows what that branch sold today, online and over the counter', function () {
    $vendor = overviewVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Till Branch']);
    $product = overviewProduct($vendor, $branch, 20);

    overviewTillSale($vendor, $branch, $product, 3, 1500.0);

    actAsOverview($vendor, $vendor->user);

    $sales = Livewire::test(StoreSelector::class)->instance()->salesToday();

    expect($sales[$branch->id]['revenue'])->toBe(4500.0)
        ->and($sales[$branch->id]['units'])->toBe(3)
        ->and($sales[$vendor->defaultStore->id]['revenue'])->toBe(0.0);

    Livewire::test(StoreSelector::class)->assertSee('Sold today');
});

test('a member sees only their own store on the grid, with its own sales', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = overviewVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Other Branch']);
    overviewProduct($vendor, $branch, 40);

    VendorRoles::seedFor($vendor);
    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    $member->stores()->attach($vendor->defaultStore->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('storekeeper');

    actAsOverview($vendor, $member);

    $stores = Livewire::test(StoreSelector::class)->instance()->stores();

    expect($stores->pluck('id')->all())->toBe([$vendor->defaultStore->id]);

    Livewire::test(StoreSelector::class)->assertDontSee('Other Branch');
});
