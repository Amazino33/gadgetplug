<?php

use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Resources\Products\Pages\ViewProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setUpProductVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Pricing Test Store']);
    $category = Category::create(['name' => 'Test Category']);

    return compact('owner', 'vendor', 'category');
}

test('profit, margin, and markup are computed correctly when cost price is set', function () {
    $data = setUpProductVendor();

    $product = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Test Widget',
        'price'          => 5000,
        'cost_price'     => 3850,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    expect($product->profit)->toEqualWithDelta(1150.0, 0.001)
        ->and($product->margin_percent)->toEqualWithDelta(23.0, 0.01)
        ->and($product->markup_percent)->toEqualWithDelta(29.87, 0.01);
});

test('profit, margin, and markup are null when cost price is missing, never faked', function () {
    $data = setUpProductVendor();

    $product = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'No Cost Widget',
        'price'          => 5000,
        'cost_price'     => null,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    expect($product->cost_price)->toBeNull()
        ->and($product->profit)->toBeNull()
        ->and($product->margin_percent)->toBeNull()
        ->and($product->markup_percent)->toBeNull();
});

test('margin percent does not divide by zero when price is zero', function () {
    $data = setUpProductVendor();

    $product = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Free Sample',
        'price'          => 0,
        'cost_price'     => 100,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    expect($product->margin_percent)->toBeNull();
});

test('the products list page defaults to table view and can switch to grid', function () {
    $data = setUpProductVendor();

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    $component = Livewire::test(ListProducts::class);

    expect($component->get('displayMode'))->toBe('table');

    $component->set('displayMode', 'grid');
    expect($component->get('displayMode'))->toBe('grid');
});

test('the products table renders real rows in both table and grid mode without error', function () {
    $data = setUpProductVendor();

    Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Table Render Widget',
        'sku'            => 'TRW-001',
        'brand'          => 'Acme',
        'price'          => 4000,
        'cost_price'     => 2500,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee('Table Render Widget')
        ->assertSee('Test Category · Acme')
        ->set('displayMode', 'grid')
        ->assertOk()
        ->assertSee('Table Render Widget');
});

test('grid mode actually produces a multi-column content grid, not just hidden columns', function () {
    // Regression guard: Filament caches the Table config during the request's
    // boot phase, before a live property update (e.g. from clicking a header
    // action) takes effect — so this only reflects reality when displayMode
    // is already set at mount time, exactly like a real ?display=grid page
    // load. If the header actions ever go back to mutating the property via
    // ->action() instead of navigating via ->url(), this test still passes
    // (it isn't exercising the click), but the ones above that assert on
    // rendered HTML would catch a live-click regression.
    $data = setUpProductVendor();

    // Needs at least one record — with zero products Filament renders an
    // empty state instead of the grid wrapper, which would falsely fail this.
    Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Grid Regression Widget',
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 5,
        'status'         => 'published',
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    $component = Livewire::test(ListProducts::class, ['displayMode' => 'grid']);

    $table = $component->instance()->getTable();

    expect($table->getContentGrid())->toBe(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => 4]);

    $html = $component->html();
    expect($html)->toContain('fi-ta-content-grid')
        ->and($html)->toContain('--cols-lg');
});

test('the product view page shows pricing and stock data', function () {
    $data = setUpProductVendor();

    $product = Product::create([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Viewable Widget',
        'sku'            => 'VW-001',
        'price'          => 5000,
        'cost_price'     => 3850,
        'stock_quantity' => 10,
        'reserved_stock' => 2,
        'status'         => 'published',
    ]);

    $this->actingAs($data['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($data['vendor']);

    Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk()
        ->assertSee('Viewable Widget')
        ->assertSee('VW-001')
        ->assertSee('₦3,850.00')
        ->assertSee('₦5,000.00');
});
