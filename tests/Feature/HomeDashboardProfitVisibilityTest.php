<?php

use App\Livewire\Vendor\HomeDashboard;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Profit is revenue minus cost: shown next to revenue, it gives the cost away.
 * The Today's Sales tile is for staff holding view_inventory_reports, and must
 * show profit only to those who also hold view_cost_price.
 */

/**
 * A single-shop vendor with one ₦10,000 sale today that cost ₦7,000, and a
 * store admin (who holds view_inventory_reports but not view_cost_price).
 *
 * @return array{vendor: Vendor, staff: User}
 */
function profitTileVendor(array $extraPermissions = []): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Profit Tile Vendor']);
    VendorRoles::seedFor($vendor);
    $store = $vendor->defaultStore;

    $staff = User::factory()->create(['name' => 'Ada']);
    $vendor->users()->attach($staff->id);
    setPermissionsTeamId($vendor->id);
    $role = Role::where('name', 'store_admin')->where('team_id', $vendor->id)->firstOrFail();
    if ($extraPermissions !== []) {
        $role->givePermissionTo($extraPermissions);
    }
    $staff->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'category_id' => Category::firstOrCreate(['name' => 'Profit Tile'])->id,
        'name' => 'Tile Phone',
        'sku' => 'TILE-'.Str::random(6),
        'price' => 10000,
        'cost_price' => 7000,
        'stock_quantity' => 10,
        'status' => 'published',
    ]);

    $sale = PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $owner->id,
        'subtotal' => 10000,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => 10000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_price' => 10000,
        'unit_cost' => 7000,
        'quantity' => 1,
        'total' => 10000,
    ]);

    return ['vendor' => $vendor, 'staff' => $staff->fresh()];
}

function actAsStaffInPanel(Vendor $vendor, User $staff): void
{
    test()->actingAs($staff);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);
}

/** The Today's Sales tile as the component built it, before any Blade layout choice. */
function todaySalesTile($component): array
{
    return collect($component->viewData('tiles'))->firstWhere('id', 'today_sales');
}

test("staff with sales access but no view_cost_price get today's revenue without the profit", function () {
    // Midday Lagos, pinned before the sale is rung. Around midnight the
    // dashboard's "today" window is an hour off (flagged separately); this
    // test is about profit, not that.
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
    $ctx = profitTileVendor();
    actAsStaffInPanel($ctx['vendor'], $ctx['staff']);

    $component = Livewire::test(HomeDashboard::class)
        ->assertSee("Today's Sales")
        ->assertSee('₦10,000')
        ->assertDontSee('Profit:')
        ->assertDontSee('₦3,000');

    $tile = todaySalesTile($component);

    expect($tile['value'])->toBe('₦10,000')
        // Not merely unrendered: never built, so no later layout change can show it.
        ->and($tile['subtext'])->toBeNull();
});

test("staff whose role is granted view_cost_price get today's profit on the tile", function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
    $ctx = profitTileVendor(['view_cost_price']);
    actAsStaffInPanel($ctx['vendor'], $ctx['staff']);

    $tile = todaySalesTile(Livewire::test(HomeDashboard::class));

    // Built for them. The Blade prints a subtext only on hero tiles, and this
    // tile is never hero, so it is not on screen yet — a layout decision,
    // separate from who may see the figure.
    expect($tile['value'])->toBe('₦10,000')
        ->and($tile['subtext'])->toBe('Profit: ₦3,000');
});
