<?php

use App\Filament\Vendor\Pages\FinancialReport;
use App\Filament\Vendor\Pages\InventoryPage;
use App\Filament\Vendor\Pages\SalesReport;
use App\Filament\Vendor\Pages\TopSellersReport;
use App\Filament\Vendor\Resources\PosSales\Pages\ListPosSales;
use App\Filament\Vendor\Resources\Roles\Pages\EditRole;
use App\Filament\Vendor\Widgets\InventoryOverviewWidget;
use App\Livewire\Vendor\HomeDashboard;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use App\Services\VendorRoles;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * "What they can see" on the Roles screen: profit, sales totals, stock value
 * and other branches are each chosen per role. The owner sees everything;
 * profit is the owner's alone until a role is given it.
 */

beforeEach(function () {
    \Livewire\Features\SupportLazyLoading\SupportLazyLoading::$disableWhileTesting = true;
});

function rvShop(): array
{
    (new VendorPermissionsSeeder())->run();
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Visibility Shop ' . uniqid()]);
    VendorRoles::seedFor($vendor);
    $main   = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $product = Product::create([
        'vendor_id' => $vendor->id, 'store_id' => $main->id,
        'category_id' => Category::firstOrCreate(['name' => 'Visibility Cat'])->id,
        'name' => 'Peak Milk', 'sku' => 'SKU-' . Str::random(6),
        'price' => 1000, 'cost_price' => 600, 'stock_quantity' => 50, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'main', 'branch', 'product');
}

/** A staff member at one branch, holding a role with exactly these permissions. */
function rvStaff(array $ctx, array $permissions, ?Store $at = null): User
{
    $user = User::factory()->create();
    $ctx['vendor']->users()->attach($user->id);
    $user->stores()->attach(($at ?? $ctx['main'])->id);
    setPermissionsTeamId($ctx['vendor']->id);
    $role = Role::create(['name' => 'r-' . uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['vendor']->id]);
    $role->givePermissionTo($permissions);
    $user->assignRole($role);

    return $user;
}

function rvAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['main']);
}

function rvSale(array $ctx, Store $store, int $total): PosSale
{
    $sale = PosSale::create([
        'reference' => 'POS-' . Str::random(10), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $store->id,
        'cashier_id' => $ctx['owner']->id, 'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0,
        'total' => $total, 'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => CarbonImmutable::now(),
    ]);
    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'product_name' => 'Peak Milk',
        'unit_price' => $total, 'unit_cost' => $total * 0.6, 'quantity' => 1, 'total' => $total,
    ]);

    return $sale;
}

test('the owner sees everything; staff see only what their role is given', function () {
    $ctx   = rvShop();
    $staff = rvStaff($ctx, ['view_inventory_reports', 'view_sales_totals']);

    foreach (['cost', 'profit', 'salesTotals', 'stockValue', 'otherBranches'] as $what) {
        expect(Visibility::{$what}($ctx['owner'], $ctx['vendor']))->toBeTrue();
    }

    expect(Visibility::salesTotals($staff, $ctx['vendor']))->toBeTrue()
        ->and(Visibility::profit($staff, $ctx['vendor']))->toBeFalse()
        ->and(Visibility::stockValue($staff, $ctx['vendor']))->toBeFalse()
        ->and(Visibility::otherBranches($staff, $ctx['vendor']))->toBeFalse();
});

test('the Sales report hides profit and margin from a role without Profit', function () {
    $ctx = rvShop();
    rvSale($ctx, $ctx['main'], 5000);

    rvAs($ctx, rvStaff($ctx, ['view_inventory_reports', 'view_sales_totals', 'view_other_branches']));
    Livewire::test(SalesReport::class)
        ->assertOk()
        ->assertSee('Revenue')
        ->assertDontSee('Margin')
        ->assertDontSeeHtml('font-medium">Profit</th>');

    rvAs($ctx, rvStaff($ctx, ['view_inventory_reports', 'view_sales_totals', 'view_other_branches', 'view_profit']));
    Livewire::test(SalesReport::class)
        ->assertSee('Margin')
        ->assertSeeHtml('font-medium">Profit</th>');
});

test('without Sales totals the sales reports are closed and the dashboard shows no takings', function () {
    $ctx  = rvShop();
    rvSale($ctx, $ctx['main'], 7777);
    $user = rvStaff($ctx, ['view_inventory_reports', 'access_pos']);
    rvAs($ctx, $user);

    expect(SalesReport::canAccess())->toBeFalse();

    Livewire::test(HomeDashboard::class)
        ->assertDontSee('₦7,777')
        ->assertDontSee("Today's Sales");
});

test('a staff member at one branch sees that branch, never the whole business', function () {
    $ctx = rvShop();
    $mine = rvSale($ctx, $ctx['main'], 3000);
    $theirs = rvSale($ctx, $ctx['branch'], 9000);
    $user = rvStaff($ctx, ['view_inventory_reports', 'view_sales_totals']);
    rvAs($ctx, $user);

    expect(Visibility::allBranches($ctx['vendor'], $user))->toBeFalse()
        ->and(TopSellersReport::canAccess())->toBeFalse();

    Livewire::test(SalesReport::class)
        ->assertSee('₦3,000.00')
        ->assertDontSee('₦12,000.00')
        ->assertDontSee('Uyo Branch');

    Livewire::test(ListPosSales::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::test(HomeDashboard::class)
        ->assertDontSee('All Stores');

    // With "Other branches" ticked, the whole business again.
    $wide = rvStaff($ctx, ['view_inventory_reports', 'view_sales_totals', 'view_other_branches']);
    rvAs($ctx, $wide);
    Livewire::test(SalesReport::class)->assertSee('₦12,000.00')->assertSee('Uyo Branch');
});

test('stock value shows only to a role with Stock value', function () {
    $ctx = rvShop();

    rvAs($ctx, rvStaff($ctx, ['view_inventory_reports']));
    Livewire::test(InventoryOverviewWidget::class, ['storeFilter' => $ctx['main']->id])
        ->assertSee('Units in Stock')
        ->assertDontSee('Stock Retail Value')
        ->assertDontSee('Gross Profit Potential');

    rvAs($ctx, rvStaff($ctx, ['view_inventory_reports', 'view_stock_value']));
    Livewire::test(InventoryOverviewWidget::class, ['storeFilter' => $ctx['main']->id])
        ->assertSee('Stock Retail Value')
        ->assertDontSee('Stock Cost Value')
        ->assertDontSee('Gross Profit Potential');
});

test('the financial report needs Profit too', function () {
    $ctx = rvShop();

    rvAs($ctx, rvStaff($ctx, ['manage_financial_reports']));
    expect(FinancialReport::canAccess())->toBeFalse();

    rvAs($ctx, rvStaff($ctx, ['manage_financial_reports', 'view_profit']));
    expect(FinancialReport::canAccess())->toBeTrue();
});

test('the Roles screen has its own "What they can see" section, saved with the rest', function () {
    $ctx  = rvShop();
    $role = Role::where('team_id', $ctx['vendor']->id)->where('name', 'store_admin')->sole();
    $ctx['vendor']->update(['owner_can_manage_roles' => true]);
    rvAs($ctx, $ctx['owner']);

    $profit = Permission::where('name', 'view_profit')->value('id');
    $sales  = Permission::where('name', 'view_sales_totals')->value('id');

    $page = Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertSee('What they can see')
        ->assertSee('Profit')
        ->assertSee('Other branches');

    $page->set('data.visibility', [(string) $profit, (string) $sales])->call('save')->assertHasNoErrors();

    $names = $role->fresh()->permissions->pluck('name');
    expect($names)->toContain('view_profit', 'view_sales_totals', 'view_inventory_reports')
        ->and($names)->not->toContain('view_stock_value', 'view_other_branches');
});

test('on release: profit goes to no role; the rest stay with the roles that see them today', function () {
    $ctx = rvShop();
    $admin = Role::where('team_id', $ctx['vendor']->id)->where('name', 'store_admin')->sole();
    $keeper = Role::where('team_id', $ctx['vendor']->id)->where('name', 'storekeeper')->sole();
    $vis = Permission::whereIn('name', ['view_profit', 'view_sales_totals', 'view_stock_value', 'view_other_branches'])->pluck('id');
    DB::table('role_has_permissions')->whereIn('permission_id', $vis)->delete();

    $migration = require database_path('migrations/2026_11_28_120000_add_visibility_permissions.php');
    $migration->up();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->permissions->pluck('name'))
        ->toContain('view_sales_totals', 'view_stock_value', 'view_other_branches')
        ->not->toContain('view_profit')
        // A storekeeper saw the stock's worth on Products: keeps that, nothing more.
        ->and($keeper->fresh()->permissions->pluck('name'))
        ->toContain('view_stock_value')
        ->not->toContain('view_profit', 'view_sales_totals', 'view_other_branches');
});
