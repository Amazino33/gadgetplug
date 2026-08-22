<?php

use App\Filament\Vendor\Pages\InventoryPage;
use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Filament\Vendor\Widgets\InventoryTableWidget;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function invVendor(): Vendor
{
    return Vendor::create([
        'user_id' => User::factory()->create()->id,
        'name'    => 'Inventory Vendor '.uniqid(),
    ]);
}

function invProduct(Vendor $vendor, Store $home, int $qty, float $price = 1000, ?float $cost = 400): Product
{
    return Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $home->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Inv Product '.uniqid(),
        'price'          => $price,
        'cost_price'     => $cost,
        'stock_quantity' => $qty,
        'status'         => 'published',
    ]);
}

function actAsInv(Vendor $vendor, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

// ─── The selector itself ────────────────────────────────────────────

test('the page opens on the branch you are working in', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    actAsInv($vendor, $vendor->user);
    ActiveStore::set($vendor, $vendor->user, $branch->id);

    Livewire::test(InventoryPage::class)
        ->assertSet('storeFilter', $branch->id);
});

test('the owner may report on every branch', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    actAsInv($vendor, $vendor->user);

    $ids = Livewire::test(InventoryPage::class)->instance()->selectableStores()->pluck('id');

    expect($ids)->toContain($vendor->defaultStore->id)->toContain($branch->id);
});

// inventory_manager rather than storekeeper: this page is gated on
// view_inventory_reports, which a storekeeper does not hold — they are refused
// the screen outright, so the interesting case is a role that CAN open it and
// is still confined to its own branch.
test('a member may only report on the branches they are assigned to', function () {
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $vendor = invVendor();
    VendorRoles::seedFor($vendor);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    $member = User::factory()->create();
    $vendor->users()->attach($member->id);
    $member->stores()->attach($branch->id);
    setPermissionsTeamId($vendor->id);
    $member->assignRole('inventory_manager');

    actAsInv($vendor, $member);

    // The selector can never widen someone's reach past their assignment.
    expect(Livewire::test(InventoryPage::class)->instance()->selectableStores()->pluck('id')->all())
        ->toBe([$branch->id]);
});

test('the filter reaches the widgets', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    actAsInv($vendor, $vendor->user);

    Livewire::test(InventoryPage::class)
        ->set('storeFilter', $branch->id)
        ->assertSet('storeFilter', $branch->id);

    expect(Livewire::test(InventoryPage::class)->instance()->getWidgetData())
        ->toHaveKey('storeFilter');
});

// ─── The figures actually change ────────────────────────────────────

test('the totals report one branch, and all branches together', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    invProduct($vendor, $vendor->defaultStore, qty: 10, price: 1000, cost: 400);   // 10,000 / 4,000
    invProduct($vendor, $branch, qty: 3, price: 2000, cost: 900);                  //  6,000 / 2,700

    actAsInv($vendor, $vendor->user);

    // The widget prints whole naira - number_format() with no decimals.
    Livewire::test(InventoryOverviewWidget::class, ['storeFilter' => null])
        ->assertSee('16,000')    // retail across both branches
        ->assertSee('6,700');    // cost across both

    Livewire::test(InventoryOverviewWidget::class, ['storeFilter' => $branch->id])
        ->assertSee('6,000')
        ->assertSee('2,700')
        // The other branch's value is absent, not merely unhighlighted.
        ->assertDontSee('16,000');
});

test('the table lists only the branch products, including sold-out ones', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    $atMain = invProduct($vendor, $vendor->defaultStore, qty: 5);
    $atBranch = invProduct($vendor, $branch, qty: 4);
    $soldOut = invProduct($vendor, $branch, qty: 0);

    actAsInv($vendor, $vendor->user);

    Livewire::test(InventoryTableWidget::class, ['storeFilter' => $branch->id])
        ->assertCanSeeTableRecords([$atBranch, $soldOut])
        ->assertCanNotSeeTableRecords([$atMain]);
});

test('with no branch chosen the table shows the whole catalogue', function () {
    $vendor = invVendor();
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Branch B']);

    $atMain = invProduct($vendor, $vendor->defaultStore, qty: 5);
    $atBranch = invProduct($vendor, $branch, qty: 4);

    actAsInv($vendor, $vendor->user);

    Livewire::test(InventoryTableWidget::class, ['storeFilter' => null])
        ->assertCanSeeTableRecords([$atMain, $atBranch]);
});

test('another vendor stock never appears whichever branch is chosen', function () {
    $vendorA = invVendor();
    $vendorB = invVendor();

    $mine = invProduct($vendorA, $vendorA->defaultStore, qty: 5);
    $theirs = invProduct($vendorB, $vendorB->defaultStore, qty: 99);

    actAsInv($vendorA, $vendorA->user);

    Livewire::test(InventoryTableWidget::class, ['storeFilter' => null])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
