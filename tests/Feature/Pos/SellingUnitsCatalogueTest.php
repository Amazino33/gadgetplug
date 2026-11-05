<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

// What the till is told about selling units, barcodes and negative stock.

function catalogueContext(array $productAttributes = [], array $vendorAttributes = []): array
{
    $owner = User::factory()->create();

    $vendor = Vendor::create(array_merge([
        'user_id' => $owner->id, 'name' => 'Drinks Depot', 'pos_min_margin_percent' => 0,
    ], $vendorAttributes));

    $product = Product::create(array_merge([
        'vendor_id' => $vendor->id,
        'category_id' => Category::create(['name' => 'Drinks'])->id,
        'name' => 'Coke 50cl',
        'base_unit' => 'Bottle',
        'barcode' => '6001',
        'price' => 450,
        'wholesale_price' => 420,
        'cost_price' => 350,
        'stock_quantity' => 60,
        'status' => 'published',
        'show_in_pos' => true,
    ], $productAttributes));

    $crate = ProductUnit::create([
        'product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 24,
        'price' => 9700, 'wholesale_price' => 9400,
    ]);

    ProductBarcode::create(['product_id' => $product->id, 'product_unit_id' => $crate->id, 'barcode' => '6024']);

    Sanctum::actingAs($owner);

    return compact('owner', 'vendor', 'product', 'crate');
}

it('sends each product with its units, prices, floors and barcodes', function () {
    $ctx = catalogueContext();

    $row = $this->getJson('/api/pos/products?vendor_id='.$ctx['vendor']->id)
        ->assertOk()
        ->json('0');

    expect($row['base_unit'])->toBe('Bottle')
        ->and($row['allow_negative'])->toBeFalse()
        ->and($row['units'])->toHaveCount(2)
        ->and($row['units'][0])->toMatchArray(['name' => 'Bottle', 'conversion_factor' => 1, 'price' => 450.0, 'wholesale_price' => 420.0])
        ->and($row['units'][1])->toMatchArray(['name' => 'Crate', 'conversion_factor' => 24, 'price' => 9700.0, 'wholesale_price' => 9400.0])
        // Haggling is on and a cost is known, so a crate may go down to the
        // cost of the 24 bottles inside it (24 × ₦350), never below.
        ->and($row['units'][1]['wholesale_min_price'])->toEqual(8400)
        // The crate's SKU is listed with its barcodes, so the till opens the
        // crate for it exactly as it does for a scan.
        ->and(collect($row['barcodes'])->pluck('barcode')->sort()->values()->all())
        ->toBe(collect(['6001', '6024', $row['units'][1] ? ProductUnit::find($row['units'][1]['id'])->sku : null])->sort()->values()->all())
        ->and($row)->not->toHaveKey('cost_price');
});

it('finds a product by a unit barcode', function () {
    $ctx = catalogueContext();

    $this->getJson('/api/pos/products/search?vendor_id='.$ctx['vendor']->id.'&q=6024')
        ->assertOk()
        ->assertJsonPath('0.id', $ctx['product']->id);
});

it('keeps an out-of-stock product on the till only when it may sell below zero', function () {
    $ctx = catalogueContext(['stock_quantity' => 0]);

    expect($this->getJson('/api/pos/products?vendor_id='.$ctx['vendor']->id)->json())->toBeEmpty();

    $ctx['vendor']->update(['allow_negative_stock' => true]);

    expect($this->getJson('/api/pos/products?vendor_id='.$ctx['vendor']->id)->json())->toHaveCount(1);
});

it('lets a cashier set the default unit, but only to one of the product\'s own units', function () {
    $ctx = catalogueContext();
    $other = ProductUnit::create([
        'product_id' => Product::create([
            'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['product']->category_id,
            'name' => 'Fanta', 'price' => 400, 'status' => 'published',
        ])->id,
        'name' => 'Pack', 'conversion_factor' => 6, 'price' => 2300,
    ]);

    $this->postJson("/api/pos/products/{$ctx['product']->id}/default-unit", [
        'vendor_id' => $ctx['vendor']->id, 'unit_id' => $ctx['crate']->id,
    ])->assertOk();

    expect($ctx['product']->fresh()->default_unit_id)->toBe($ctx['crate']->id);

    $this->postJson("/api/pos/products/{$ctx['product']->id}/default-unit", [
        'vendor_id' => $ctx['vendor']->id, 'unit_id' => $other->id,
    ])->assertNotFound();
});

it('refuses a main barcode that already belongs to another product\'s unit', function () {
    $ctx = catalogueContext();

    expect(fn () => Product::create([
        'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['product']->category_id,
        'name' => 'Clash', 'price' => 100, 'barcode' => '6024',
    ]))->toThrow(ValidationException::class);
});

it('says why a scanned code is not on sale at the till', function () {
    $ctx = catalogueContext();
    $url = '/api/pos/products/why-not-sellable?vendor_id='.$ctx['vendor']->id.'&code=';

    // Sellable: nothing to explain.
    $this->getJson($url.'6001')->assertOk()->assertJson(['found' => true, 'reason' => null]);

    // Unknown everywhere in this shop.
    $this->getJson($url.'0000000000')->assertOk()->assertJson(['found' => false]);

    // A unit's own code is recognised too.
    $ctx['product']->update(['status' => 'draft']);
    expect($this->getJson($url.'6024')->json('reason'))->toContain('not published');

    $ctx['product']->update(['status' => 'published', 'show_in_pos' => false]);
    expect($this->getJson($url.'6001')->json('reason'))->toContain('Show in POS');

    $ctx['product']->update(['show_in_pos' => true]);
    app(AdjustStockAction::class)->execute($ctx['product']->id, -60, 'stock_adjustment');
    expect($this->getJson($url.'6001')->json('reason'))->toContain('no stock');

    $ctx['vendor']->update(['allow_negative_stock' => true]);
    $this->getJson($url.'6001')->assertJson(['reason' => null]);
});

it('hands the catalogue out a page at a time, in id order, and whole without a limit', function () {
    $ctx = catalogueContext();

    foreach (range(1, 4) as $i) {
        Product::create([
            'vendor_id' => $ctx['vendor']->id, 'category_id' => $ctx['product']->category_id,
            'name' => "Extra {$i}", 'price' => 100, 'stock_quantity' => 5, 'status' => 'published', 'show_in_pos' => true,
        ]);
    }

    $base = '/api/pos/products?vendor_id='.$ctx['vendor']->id;

    $first = collect($this->getJson($base.'&after_id=0&limit=2')->assertOk()->json())->pluck('id');
    $second = collect($this->getJson($base.'&after_id='.$first->last().'&limit=2')->json())->pluck('id');
    $third = collect($this->getJson($base.'&after_id='.$second->last().'&limit=2')->json())->pluck('id');

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        ->and($third)->toHaveCount(1)
        ->and($first->merge($second)->merge($third)->all())->toBe(Product::orderBy('id')->pluck('id')->all())
        ->and($this->getJson($base)->json())->toHaveCount(5);
});
