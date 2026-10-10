<?php

use App\Models\Category;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

// Selling one product in several units — a bottle, a half crate, a crate —
// while stock, cost and reports keep counting bottles.

function unitsContext(array $productAttributes = [], array $vendorAttributes = []): array
{
    $owner = User::factory()->create();

    $vendor = Vendor::create(array_merge([
        'user_id'                => $owner->id,
        'name'                   => 'Drinks Depot',
        'pos_min_margin_percent' => 0,
    ], $vendorAttributes));

    $category = Category::create(['name' => 'Drinks']);

    $product = Product::create(array_merge([
        'vendor_id'                => $vendor->id,
        'category_id'              => $category->id,
        'name'                     => 'Coke 50cl',
        'base_unit'                => 'Bottle',
        'price'                    => 450,
        'wholesale_price'          => 420,
        'cost_price'               => 350,
        'allow_pos_price_override' => false,
        'stock_quantity'           => 60,
        'status'                   => 'published',
        'show_in_pos'              => true,
    ], $productAttributes));

    $crate = ProductUnit::create([
        'product_id' => $product->id, 'name' => 'Crate', 'conversion_factor' => 24,
        'price' => 9700, 'wholesale_price' => 9400, 'sort_order' => 2,
    ]);

    $half = ProductUnit::create([
        'product_id' => $product->id, 'name' => 'Half Crate', 'conversion_factor' => 12,
        'price' => 5000, 'wholesale_price' => null, 'sort_order' => 1,
    ]);

    return compact('owner', 'vendor', 'product', 'crate', 'half');
}

function unitSale(array $ctx, array $items)
{
    Sanctum::actingAs($ctx['owner']);

    return test()->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => $items,
        'payment_method'  => 'cash',
        'amount_tendered' => 100000,
        'vat_rate'        => 0,
    ]);
}

function unitLine(array $ctx, ?ProductUnit $unit, int $qty, float $price, string $type = 'retail'): array
{
    return array_filter([
        'product_id'      => $ctx['product']->id,
        'product_name'    => 'Coke 50cl',
        'unit_price'      => $price,
        'quantity'        => $qty * ($unit?->conversion_factor ?? 1),
        'product_unit_id' => $unit?->id,
        'unit_qty'        => $unit ? $qty : null,
        'price_type'      => $type,
    ], fn ($v) => $v !== null);
}

it('gives every new product a base unit that mirrors its price and is its default', function () {
    $ctx = unitsContext();
    $product = $ctx['product']->fresh();

    $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->first();

    expect($base)->not->toBeNull()
        ->and($base->name)->toBe('Bottle')
        ->and((float) $base->price)->toBe(450.0)
        ->and((float) $base->wholesale_price)->toBe(420.0)
        ->and($base->conversion_factor)->toBe(1)
        ->and($product->default_unit_id)->toBe($base->id);

    $product->update(['price' => 500]);

    expect((float) $base->fresh()->price)->toBe(500.0);
});

it('sells a crate at the crate price and takes 24 bottles off the shelf', function () {
    $ctx = unitsContext();

    unitSale($ctx, [unitLine($ctx, $ctx['crate'], 1, 9700)])->assertCreated();

    $item = PosSaleItem::firstOrFail();

    expect((int) $item->quantity)->toBe(24)
        ->and((int) $item->unit_qty)->toBe(1)
        ->and($item->unit_name)->toBe('Crate')
        ->and((float) $item->sold_unit_price)->toBe(9700.0)
        ->and((float) $item->total)->toBe(9700.0)
        ->and((float) $item->sale->subtotal)->toBe(9700.0)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(36);
});

it('sells a single bottle and a half crate in the same sale', function () {
    $ctx = unitsContext();
    $base = ProductUnit::where('product_id', $ctx['product']->id)->where('is_base', true)->first();

    unitSale($ctx, [
        unitLine($ctx, $base, 1, 450),
        unitLine($ctx, $ctx['half'], 1, 5000),
    ])->assertCreated();

    expect((float) PosSaleItem::sum('total'))->toBe(5450.0)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(47);
});

it('sells at the wholesale price, which the floor would otherwise refuse', function () {
    $ctx = unitsContext();

    unitSale($ctx, [unitLine($ctx, $ctx['crate'], 2, 9400, 'wholesale')])->assertCreated();

    $item = PosSaleItem::firstOrFail();

    expect($item->price_type)->toBe('wholesale')
        ->and((float) $item->total)->toBe(18800.0)
        ->and((int) $item->quantity)->toBe(48);
});

it('treats a wholesale request on a unit with no wholesale price as retail', function () {
    $ctx = unitsContext();

    // The half crate has no wholesale price, so ₦4,000 is below its ₦5,000 retail floor.
    unitSale($ctx, [unitLine($ctx, $ctx['half'], 1, 4000, 'wholesale')])->assertStatus(422);

    unitSale($ctx, [unitLine($ctx, $ctx['half'], 1, 5000, 'wholesale')])->assertCreated();

    expect(PosSaleItem::firstOrFail()->price_type)->toBe('retail');
});

it('still refuses a sale below zero when the shop does not allow it, naming the product', function () {
    $ctx = unitsContext();

    unitSale($ctx, [unitLine($ctx, $ctx['crate'], 3, 9700)])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Insufficient stock for product: Coke 50cl']);

    expect($ctx['product']->fresh()->stock_quantity)->toBe(60)
        ->and(PosSaleItem::count())->toBe(0);
});

it('sells below zero when the shop allows it, and marks the cost as estimated', function () {
    $ctx = unitsContext([], ['allow_negative_stock' => true]);

    unitSale($ctx, [unitLine($ctx, $ctx['crate'], 3, 9700)])->assertCreated();

    $item = PosSaleItem::firstOrFail();

    expect($ctx['product']->fresh()->stock_quantity)->toBe(-12)
        ->and($item->cost_estimated)->toBeTrue()
        ->and((float) $item->cost_total)->toBe(72 * 350.0);
});

it('lets a product override the shop setting either way', function () {
    $blocked = unitsContext(['negative_stock_override' => false], ['allow_negative_stock' => true]);
    unitSale($blocked, [unitLine($blocked, $blocked['crate'], 3, 9700)])->assertStatus(422);

    $allowed = unitsContext(['negative_stock_override' => true, 'name' => 'Fanta'], ['allow_negative_stock' => false]);
    unitSale($allowed, [unitLine($allowed, $allowed['crate'], 3, 9700)])->assertCreated();
});

it('sells an old-style line with no unit exactly as before', function () {
    $ctx = unitsContext();

    unitSale($ctx, [[
        'product_id' => $ctx['product']->id, 'product_name' => 'Coke 50cl',
        'unit_price' => 450, 'quantity' => 3,
    ]])->assertCreated();

    $item = PosSaleItem::firstOrFail();

    expect((int) $item->quantity)->toBe(3)
        ->and((float) $item->total)->toBe(1350.0)
        ->and($item->unit_qty)->toBeNull()
        ->and($item->product_unit_id)->toBeNull()
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(57);
});

it('refuses a unit that belongs to another product', function () {
    $ctx = unitsContext();
    $other = unitsContext(['name' => 'Sprite']);

    unitSale($ctx, [unitLine($ctx, $other['crate'], 1, 9700)])->assertStatus(422);
});

it('syncs an offline crate sale the same way', function () {
    $ctx = unitsContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'     => 'offline-crate-1',
            'items'          => [unitLine($ctx, $ctx['crate'], 1, 9700) + ['total' => 9700]],
            'payment_method' => 'cash',
            'subtotal'       => 9700,
            'total'          => 9700,
            'vat_amount'     => 0,
            'completed_at'   => now()->toIso8601String(),
        ]],
    ])->assertOk()->assertJsonFragment(['status' => 'synced']);

    expect((int) PosSaleItem::firstOrFail()->quantity)->toBe(24)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe(36);
});
