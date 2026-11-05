<?php

use App\Filament\Vendor\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use Database\Seeders\VendorPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The Selling Units section of the product form.

function sellingUnitsFormContext(): array
{
    (new VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Units Form Store']);
    VendorRoles::seedFor($vendor);
    $category = Category::create(['name' => 'Drinks']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Coke 50cl',
        'sku' => 'COKE-50',
        'barcode' => '6001',
        'price' => 450,
        'cost_price' => 350,
        'stock_quantity' => 60,
        'status' => 'published',
    ]);

    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($vendor);

    return compact('owner', 'vendor', 'category', 'product');
}

test('a vendor adds a crate with its own price, wholesale price and barcode', function () {
    $ctx = sellingUnitsFormContext();
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
        ->fillForm([
            'base_unit' => 'Bottle',
            'wholesale_price' => 420,
            'extraUnits' => [[
                'name' => 'Crate',
                'conversion_factor' => 24,
                'price' => 9700,
                'wholesale_price' => 9400,
                'is_active' => true,
                'barcodes' => [['barcode' => '6024']],
            ]],
            'extraBaseBarcodes' => [['barcode' => '6001-B']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $product = $ctx['product']->fresh();
    $crate = ProductUnit::where('product_id', $product->id)->where('name', 'Crate')->first();
    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    expect($crate)->not->toBeNull()
        ->and($crate->vendor_id)->toBe($ctx['vendor']->id)
        ->and($crate->conversion_factor)->toBe(24)
        ->and((float) $crate->price)->toBe(9700.0)
        ->and((float) $crate->wholesale_price)->toBe(9400.0)
        ->and($base->name)->toBe('Bottle')
        ->and((float) $base->wholesale_price)->toBe(420.0)
        ->and(ProductBarcode::where('barcode', '6024')->value('product_unit_id'))->toBe($crate->id)
        ->and(ProductBarcode::where('barcode', '6001-B')->whereNull('product_unit_id')->value('product_id'))->toBe($product->id)
        // The main barcode is still mirrored, and still only once.
        ->and(ProductBarcode::where('barcode', '6001')->count())->toBe(1);
});

test('a unit must hold at least one base unit and cannot have a negative price', function () {
    $ctx = sellingUnitsFormContext();
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
        ->fillForm([
            'extraUnits' => [[
                'name' => 'Crate', 'conversion_factor' => 0, 'price' => -5, 'is_active' => true, 'barcodes' => [],
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors(['extraUnits.0.conversion_factor', 'extraUnits.0.price']);

    $undoRepeaterFake();
});

test('a barcode another product already uses is refused', function () {
    $ctx = sellingUnitsFormContext();

    Product::create([
        'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['category']->id,
        'name' => 'Fanta', 'price' => 400, 'barcode' => '7001', 'status' => 'published',
    ]);

    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
        ->fillForm([
            'extraUnits' => [[
                'name' => 'Crate', 'conversion_factor' => 24, 'price' => 9700, 'is_active' => true,
                'barcodes' => [['barcode' => '7001']],
            ]],
        ])
        ->call('save')
        ->assertHasFormErrors(['extraUnits.0.barcodes.0.barcode']);

    $undoRepeaterFake();

    expect(ProductUnit::where('name', 'Crate')->exists())->toBeFalse();
});

test('the negative-stock override saves as allow, block or follow-the-shop', function () {
    $ctx = sellingUnitsFormContext();

    foreach (['1' => true, '0' => false, null => null] as $choice => $expected) {
        Livewire::test(EditProduct::class, ['record' => $ctx['product']->getRouteKey()])
            ->fillForm(['negative_stock_override' => $choice === '' ? null : (string) $choice])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($ctx['product']->fresh()->negative_stock_override)->toBe($expected);
    }
});
