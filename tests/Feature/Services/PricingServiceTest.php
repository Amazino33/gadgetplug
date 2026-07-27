<?php

use App\Models\Category;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PricingService;

function setUpPricingVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Pricing Test Store']);
    $category = Category::create(['name' => 'Pricing Test Category']);
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Test Supplier']);

    return compact('owner', 'vendor', 'category', 'supplier');
}

function makePricingProduct(array $ctx, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'category_id' => $ctx['category']->id,
        'name' => 'Pricing Test Product',
        'price' => 1000,
        'status' => 'published',
    ], $overrides));
}

function makePricingProcurement(array $ctx, array $overrides = []): Procurement
{
    return Procurement::create(array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'supplier_id' => $ctx['supplier']->id,
        'created_by' => $ctx['owner']->id,
        'status' => 'pending',
    ], $overrides));
}

// ── Pure math ──────────────────────────────────────────────────────────

test('single-line landed cost matches the reference example', function () {
    $service = new PricingService;

    $factor = $service->logisticsFactor(1500, 20000);
    expect($factor)->toEqualWithDelta(1.075, 0.0001);

    $landed = $service->landedUnitCost(20000, $factor);
    expect($landed)->toEqualWithDelta(21500.0, 0.01);
});

test('charger suggested price rounds to 4500 under the cap', function () {
    $service = new PricingService;

    expect($service->suggestedPrice(2800, 0.60))->toEqualWithDelta(4500.0, 0.01);
});

test('profit cap engages on expensive stock', function () {
    $service = new PricingService;

    // raw = 180000 * 1.45 = 261000, raw profit 81000 > cap 50000
    // -> price = 180000 + 50000 = 230000, already a multiple of 500.
    expect($service->suggestedPrice(180000, 0.45))->toEqualWithDelta(230000.0, 0.01);
});

test('rounding direction — nearest 500, both up and down', function () {
    $service = new PricingService;

    expect($service->roundToNearest(4480, 500))->toEqualWithDelta(4500.0, 0.01)
        ->and($service->roundToNearest(4240, 500))->toEqualWithDelta(4000.0, 0.01);
});

test('provisional pricing — null logistics cost collapses to factor 1', function () {
    $service = new PricingService;

    expect($service->logisticsFactor(null, 20000))->toBe(1.0)
        ->and($service->landedUnitCost(20000, 1.0))->toEqualWithDelta(20000.0, 0.01);
});

test('zero trip value also collapses to factor 1, avoiding division by zero', function () {
    $service = new PricingService;

    expect($service->logisticsFactor(1500, 0))->toBe(1.0);
});

test('the price methods are deterministic — identical inputs, identical outputs', function () {
    $service = new PricingService;

    $first = $service->suggestedPrice(21500, 0.55);
    $second = $service->suggestedPrice(21500, 0.55);

    expect($first)->toBe($second);
});

// ── Orchestration (priceTrip — touches the DB, read-only) ────────────────

test('multi-line trips allocate logistics cost by value, summing back to the trip logistics cost', function () {
    $ctx = setUpPricingVendor();
    $service = new PricingService;

    $procurement = makePricingProcurement($ctx, ['logistics_cost' => 1500]);

    $productA = makePricingProduct($ctx, ['name' => 'Product A']);
    $productB = makePricingProduct($ctx, ['name' => 'Product B']);

    $lineA = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $productA->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);
    $lineB = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $productB->id,
        'quantity' => 1, 'unit_cost' => 5000, 'selling_price' => 0,
    ]);

    $result = $service->priceTrip($procurement);

    expect($result[$lineA->id]['landed_unit_cost'])->toEqualWithDelta(21200.0, 0.01)
        ->and($result[$lineB->id]['landed_unit_cost'])->toEqualWithDelta(5300.0, 0.01);

    $allocatedLogistics = ($result[$lineA->id]['landed_unit_cost'] - 20000)
        + ($result[$lineB->id]['landed_unit_cost'] - 5000);

    expect($allocatedLogistics)->toEqualWithDelta(1500.0, 0.01);
});

test('a line whose category has no markup set falls back to the config default', function () {
    $ctx = setUpPricingVendor();
    $service = new PricingService;

    expect($ctx['category']->markup)->toBeNull();

    $procurement = makePricingProcurement($ctx, ['logistics_cost' => null]);
    $product = makePricingProduct($ctx);

    $line = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 2800, 'selling_price' => 0,
    ]);

    $result = $service->priceTrip($procurement);

    // fallback_markup is 0.50 in config/pricing.php -> raw 4200 -> round to 4000
    expect($result[$line->id]['suggested_price'])->toEqualWithDelta(4000.0, 0.01);
});

test('priceTrip is read-only — it does not write landed_unit_cost or suggested_price back to the line', function () {
    $ctx = setUpPricingVendor();
    $service = new PricingService;

    $procurement = makePricingProcurement($ctx, ['logistics_cost' => 1500]);
    $product = makePricingProduct($ctx);

    $line = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_cost' => 20000, 'selling_price' => 0,
    ]);

    $service->priceTrip($procurement);

    expect($line->fresh()->landed_unit_cost)->toBeNull()
        ->and($line->fresh()->suggested_price)->toBeNull();
});
