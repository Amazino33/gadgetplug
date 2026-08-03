<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;

function makeReportVendor(string $name): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name]);
    $category = Category::firstOrCreate(['name' => 'Reporting Test Category']);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => $name.' Widget',
        'sku' => 'SKU-'.Str::random(6),
        'price' => 1000,
        'cost_price' => 600,
        'stock_quantity' => 100,
        'status' => 'published',
        'published_at' => now(),
    ]);

    return compact('owner', 'vendor', 'product');
}

function makePosSale(array $ctx, float $unitPrice, float $unitCost, int $qty, ?CarbonImmutable $at = null): PosSale
{
    $at ??= CarbonImmutable::now();
    $subtotal = $unitPrice * $qty;

    $sale = PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $ctx['vendor']->id,
        'cashier_id' => $ctx['owner']->id,
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        // VAT is deliberately non-zero: revenue must exclude it.
        'vat_amount' => round($subtotal * 0.075, 2),
        'total' => $subtotal + round($subtotal * 0.075, 2),
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => $at,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $ctx['product']->id,
        'product_name' => $ctx['product']->name,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'quantity' => $qty,
        'discount_amount' => 0,
        'total' => $subtotal,
    ]);

    return $sale;
}

function makeOnlineOrder(array $ctx, float $unitPrice, float $unitCost, int $qty, string $status = 'paid'): Order
{
    $order = Order::create([
        'user_id' => $ctx['owner']->id,
        'reference' => 'GP-'.Str::random(10),
        'customer_name' => 'Test Buyer',
        'customer_email' => 'buyer@example.com',
        'customer_phone' => '08010000000',
        'shipping_address' => 'Uyo',
        'total_amount' => $unitPrice * $qty,
        'status' => $status,
        'payment_method' => 'paystack',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $ctx['product']->id,
        'vendor_id' => $ctx['vendor']->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
    ]);

    return $order;
}

it('counts POS sales, which the dashboard previously ignored entirely', function () {
    $ctx = makeReportVendor('Pos Only Store');
    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 3);

    $summary = app(SalesReportService::class)->summary(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    // 3 x 1000 = 3000 revenue, excluding the 7.5% VAT on top of it
    expect($summary['revenue'])->toEqualWithDelta(3000.0, 0.01)
        ->and($summary['profit'])->toEqualWithDelta(3000.0 - 1800.0, 0.01)
        ->and($summary['orders'])->toBe(1)
        ->and($summary['units'])->toBe(3);
});

it('adds online and POS revenue together', function () {
    $ctx = makeReportVendor('Both Channels Store');
    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 2);     // 2000 rev / 1200 cost
    makeOnlineOrder($ctx, unitPrice: 1500, unitCost: 900, qty: 2); // 3000 rev / 1800 cost

    $summary = app(SalesReportService::class)->summary(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($summary['revenue'])->toEqualWithDelta(5000.0, 0.01)
        ->and($summary['profit'])->toEqualWithDelta(2000.0, 0.01)
        ->and($summary['online_revenue'])->toEqualWithDelta(3000.0, 0.01)
        ->and($summary['pos_revenue'])->toEqualWithDelta(2000.0, 0.01);
});

it('never counts another store\'s sales', function () {
    $mine = makeReportVendor('My Store');
    $theirs = makeReportVendor('Their Store');

    makePosSale($mine, unitPrice: 1000, unitCost: 600, qty: 1);
    makePosSale($theirs, unitPrice: 9999, unitCost: 100, qty: 5);
    makeOnlineOrder($theirs, unitPrice: 9999, unitCost: 100, qty: 5);

    $summary = app(SalesReportService::class)->summary(
        $mine['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($summary['revenue'])->toEqualWithDelta(1000.0, 0.01);
});

it('excludes unpaid orders and voided POS sales', function () {
    $ctx = makeReportVendor('Unpaid Store');

    makeOnlineOrder($ctx, unitPrice: 5000, unitCost: 100, qty: 1, status: 'pending');
    makePosSale($ctx, unitPrice: 4000, unitCost: 100, qty: 1)->update(['status' => 'voided']);

    $summary = app(SalesReportService::class)->summary(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($summary['revenue'])->toEqualWithDelta(0.0, 0.01)
        ->and($summary['orders'])->toBe(0);
});

it('respects the date range', function () {
    $ctx = makeReportVendor('Range Store');

    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 1, at: CarbonImmutable::now());
    makePosSale($ctx, unitPrice: 7000, unitCost: 600, qty: 1, at: CarbonImmutable::now()->subDays(10));

    $summary = app(SalesReportService::class)->summary(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    // Only the recent sale falls inside the window
    expect($summary['revenue'])->toEqualWithDelta(1000.0, 0.01);
});

it('falls back to current cost price and flags the figure as estimated', function () {
    $ctx = makeReportVendor('Legacy Store');

    // A row written before unit_cost existed
    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 1);
    PosSaleItem::query()->update(['unit_cost' => null]);

    $summary = app(SalesReportService::class)->summary(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    // Falls back to the product's current cost_price of 600
    expect($summary['profit'])->toEqualWithDelta(400.0, 0.01)
        ->and($summary['cost_is_estimated'])->toBeTrue();
});

it('reports top products across both channels', function () {
    $ctx = makeReportVendor('Top Store');
    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 2);
    makeOnlineOrder($ctx, unitPrice: 1000, unitCost: 600, qty: 3);

    $top = app(SalesReportService::class)->topProducts(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    // The same product sold on both channels collapses into one ranked row
    expect($top)->toHaveCount(1)
        ->and($top->first()['units'])->toBe(5)
        ->and($top->first()['revenue'])->toEqualWithDelta(5000.0, 0.01);
});

it('breaks POS sales down by cashier, not just a store-wide total', function () {
    $ctx = makeReportVendor('Cashier Breakdown Store');
    $cashierB = User::factory()->create(['name' => 'Cashier B']);

    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 2); // rung up by $ctx['owner']

    $saleByB = PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $ctx['vendor']->id,
        'cashier_id' => $cashierB->id,
        'subtotal' => 3000,
        'vat_amount' => 0,
        'total' => 3000,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => CarbonImmutable::now(),
    ]);
    PosSaleItem::create([
        'pos_sale_id' => $saleByB->id,
        'product_id' => $ctx['product']->id,
        'product_name' => $ctx['product']->name,
        'unit_price' => 1500,
        'quantity' => 2,
        'total' => 3000,
    ]);

    $breakdown = app(SalesReportService::class)->cashierBreakdown(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    )->keyBy('cashier_name');

    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[$ctx['owner']->name]['revenue'])->toEqualWithDelta(2000.0, 0.01)
        ->and($breakdown['Cashier B']['revenue'])->toEqualWithDelta(3000.0, 0.01)
        ->and($breakdown['Cashier B']['orders'])->toBe(1);
});

it('excludes voided sales from the cashier breakdown', function () {
    $ctx = makeReportVendor('Voided Cashier Store');
    makePosSale($ctx, unitPrice: 1000, unitCost: 600, qty: 1)->update(['status' => 'voided']);

    $breakdown = app(SalesReportService::class)->cashierBreakdown(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($breakdown)->toBeEmpty();
});

it('reports online order counts by status, including ones not yet earning revenue', function () {
    $ctx = makeReportVendor('Status Breakdown Store');
    makeOnlineOrder($ctx, unitPrice: 1000, unitCost: 600, qty: 1, status: 'paid');
    makeOnlineOrder($ctx, unitPrice: 1000, unitCost: 600, qty: 1, status: 'confirmed');
    makeOnlineOrder($ctx, unitPrice: 1000, unitCost: 600, qty: 1, status: 'confirmed');
    makeOnlineOrder($ctx, unitPrice: 1000, unitCost: 600, qty: 1, status: 'cancelled');

    $breakdown = app(SalesReportService::class)->onlineOrderStatusBreakdown(
        $ctx['vendor']->id,
        CarbonImmutable::now()->subDay(),
        CarbonImmutable::now()->addDay(),
    );

    expect($breakdown)->toBe(['paid' => 1, 'confirmed' => 2, 'cancelled' => 1]);
});
