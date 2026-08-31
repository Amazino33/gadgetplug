<?php

use App\Filament\Vendor\Pages\SalesReport;
use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function reportPanelVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Report Panel '.uniqid()]);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Report Panel Cat'])->id,
        'name'           => 'Panel Widget',
        'sku'            => 'SKU-'.Str::random(6),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 50,
        'status'         => 'published',
    ]);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($vendor);

    return compact('owner', 'vendor', 'branch', 'product');
}

function panelSale(array $ctx, Store $store, int $qty): void
{
    $sale = PosSale::create([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $ctx['vendor']->id,
        'store_id'        => $store->id,
        'cashier_id'      => $ctx['owner']->id,
        'subtotal'        => 1000 * $qty,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => 1000 * $qty,
        'payment_method'  => 'cash',
        'status'          => 'completed',
        'completed_at'    => CarbonImmutable::now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $ctx['product']->id,
        'product_name' => $ctx['product']->name,
        'unit_price'   => 1000,
        'unit_cost'    => 600,
        'quantity'     => $qty,
        'total'        => 1000 * $qty,
    ]);
}

test('the report opens on every branch, and lists each one', function () {
    $ctx = reportPanelVendor();
    panelSale($ctx, $ctx['vendor']->defaultStore, 3);
    panelSale($ctx, $ctx['branch'], 2);

    Livewire::test(SalesReport::class)
        ->assertOk()
        ->assertSee('Sales by store')
        ->assertSee('Uyo Branch')
        // Vendor-wide until a branch is picked.
        ->assertSee('5,000.00');
});

test('picking a branch narrows every figure to it', function () {
    $ctx = reportPanelVendor();
    panelSale($ctx, $ctx['vendor']->defaultStore, 3);
    panelSale($ctx, $ctx['branch'], 2);

    Livewire::test(SalesReport::class)
        ->set('filters.store', $ctx['branch']->id)
        ->assertSee('Showing')
        ->assertSee('2,000.00')
        ->assertDontSee('5,000.00');
});

test('a branch the user cannot reach is ignored rather than honoured', function () {
    // Built before the panel is booted: creating a second vendor once Filament
    // has a tenant set trips its own tenancy scoping, which is not what this
    // test is about.
    $foreign = Store::create([
        'vendor_id' => Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Other'])->id,
        'name'      => 'Foreign Branch',
    ]);

    $ctx = reportPanelVendor();
    panelSale($ctx, $ctx['vendor']->defaultStore, 3);
    panelSale($ctx, $ctx['branch'], 2);

    Livewire::test(SalesReport::class)
        ->set('filters.store', $foreign->id)
        // Falls back to the whole vendor rather than reporting a branch that
        // is none of this user's business.
        ->assertSee('5,000.00')
        ->assertDontSee('Foreign Branch');
});
