<?php

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\ProductVelocityService;
use App\Services\Reporting\RestockAnalysisResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function velocityVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Velocity Store ' . uniqid()]);
    $category = Category::create(['name' => 'Velocity Category ' . uniqid()]);

    return compact('owner', 'vendor', 'category');
}

function velocityProduct(array $data, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id'      => $data['vendor']->id,
        'category_id'    => $data['category']->id,
        'name'           => 'Velocity Widget ' . uniqid(),
        'price'          => 1000,
        'cost_price'     => 500,
        'stock_quantity' => 0,
        'status'         => 'published',
        'created_at'     => now()->subYear(), // long-established unless overridden
    ], $overrides));
}

function deliverOnline(array $data, Product $product, int $quantity, $recognizedAt): void
{
    $order = Order::create([
        'reference'             => 'ORD-VEL-' . uniqid(),
        'customer_name'         => 'Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone'        => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount'          => $product->price * $quantity,
        'status'                => 'delivered', 'payment_method' => 'pay_on_delivery',
        'revenue_recognized_at' => $recognizedAt,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);
}

function deliverPos(array $data, Product $product, int $quantity, $completedAt, string $status = 'completed'): void
{
    $sale = PosSale::create([
        'reference'    => 'POS-VEL-' . uniqid(),
        'vendor_id'    => $data['vendor']->id,
        'cashier_id'   => $data['owner']->id,
        'subtotal'     => $product->price * $quantity,
        'total'        => $product->price * $quantity,
        'payment_method' => 'cash',
        'status'       => $status,
        'completed_at' => $completedAt,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'unit_price' => $product->price, 'quantity' => $quantity, 'total' => $product->price * $quantity,
    ]);
}

function ledgerEvent(array $data, Product $product, int $change, string $type, $at): InventoryLedger
{
    return InventoryLedger::create([
        'vendor_id' => $data['vendor']->id, 'product_id' => $product->id,
        'transaction_type' => $type, 'quantity_change' => $change, 'created_at' => $at,
    ]);
}

test('daily velocity combines online and POS units, divided by the window length', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 100]);

    deliverOnline($data, $product, 9, now()->subDays(5));
    deliverPos($data, $product, 6, now()->subDays(2));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->dailyVelocity)->toEqualWithDelta(15 / 30, 0.0001);
});

test('a voided POS sale never counts toward velocity', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 100]);

    deliverPos($data, $product, 10, now()->subDay(), status: 'voided');

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->dailyVelocity)->toBe(0.0);
});

test('a cancelled online order never counts toward velocity', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 100]);

    Order::create([
        'reference' => 'ORD-CANCEL', 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => 1000,
        'status' => 'cancelled', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => null,
    ]);

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->dailyVelocity)->toBe(0.0);
});

test('days of cover and reorder quantity are correct for a healthy-velocity product', function () {
    $data = velocityVendor();
    // 30 units delivered over a 30-day window = exactly 1/day.
    $product = velocityProduct($data, ['stock_quantity' => 20]);
    deliverOnline($data, $product, 30, now()->subDays(10));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30, targetCoverDays: 30);

    expect($result->dailyVelocity)->toBe(1.0)
        ->and($result->daysOfCover)->toBe(20.0)
        // reorder_quantity only applies to urgent/reorder_now tiers — 20 days
        // of cover is healthy (> 5+5), so no quantity is suggested here.
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_HEALTHY)
        ->and($result->reorderQuantity)->toBe(0);
});

test('reorder quantity restocks to the target cover, net of what is already on hand', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 3]); // urgent tier
    deliverOnline($data, $product, 30, now()->subDays(10)); // velocity = 1/day

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30, targetCoverDays: 30);

    // ceil(30 * 1 - 3) = 27
    expect($result->tier)->toBe(RestockAnalysisResult::TIER_URGENT)
        ->and($result->reorderQuantity)->toBe(27);
});

test('urgent tier at exactly the lead-time boundary', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 5]); // days_of_cover = 5 = leadTime
    deliverOnline($data, $product, 30, now()->subDays(10));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30, leadTimeDays: 5);

    expect($result->daysOfCover)->toBe(5.0)
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_URGENT);
});

test('reorder-now tier just above the lead-time boundary and at the buffer boundary', function () {
    $data = velocityVendor();
    $justAbove = velocityProduct($data, ['stock_quantity' => 6]); // days_of_cover = 6
    $atBuffer = velocityProduct($data, ['stock_quantity' => 10]); // days_of_cover = 10 = lead(5)+buffer(5)
    deliverOnline($data, $justAbove, 30, now()->subDays(10));
    deliverOnline($data, $atBuffer, 30, now()->subDays(10));

    $service = app(ProductVelocityService::class);

    expect($service->forProduct($justAbove, windowDays: 30, leadTimeDays: 5)->tier)
        ->toBe(RestockAnalysisResult::TIER_REORDER_NOW)
        ->and($service->forProduct($atBuffer, windowDays: 30, leadTimeDays: 5)->tier)
        ->toBe(RestockAnalysisResult::TIER_REORDER_NOW);
});

test('healthy tier just above the safety-buffer boundary', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 11]); // days_of_cover = 11 > 10
    deliverOnline($data, $product, 30, now()->subDays(10));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30, leadTimeDays: 5);

    expect($result->tier)->toBe(RestockAnalysisResult::TIER_HEALTHY)
        ->and($result->reorderQuantity)->toBe(0);
});

test('a product created within the window is flagged Watch with no suggested quantity, even with some sales', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 1, 'created_at' => now()->subDays(10)]);
    deliverOnline($data, $product, 5, now()->subDays(2));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->isNew)->toBeTrue()
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_WATCH)
        ->and($result->reorderQuantity)->toBe(0);
});

test('zero velocity with stock on hand and no stockout history is a dead-stock candidate, not a divide-by-zero', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 15]);
    // Stock arrived once, long before the window, and never moved since.
    ledgerEvent($data, $product, 15, 'restock', now()->subDays(90));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->dailyVelocity)->toBe(0.0)
        ->and($result->daysOfCover)->toBeNull()
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_DEAD_STOCK_CANDIDATE);
});

test('zero velocity with zero stock and no stockout history is Review, not assumed dead', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 0]);

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->tier)->toBe(RestockAnalysisResult::TIER_REVIEW);
});

test('a product starved out of stock for most of the window is flagged Starved, not dead', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 0]);

    // Sold out well before the window even started; nothing since, so the
    // window itself sees zero ledger activity and a flat zero balance.
    ledgerEvent($data, $product, 5, 'restock', now()->subDays(60));
    ledgerEvent($data, $product, -5, 'pos_sale', now()->subDays(55));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->dailyVelocity)->toBe(0.0)
        ->and($result->daysOutOfStock)->toBe(30)
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_STARVED_REVIEW);
});

test('the stockout guard can be switched off, falling back to plain dead-stock/review classification', function () {
    $data = velocityVendor();
    $product = velocityProduct($data, ['stock_quantity' => 0]);
    ledgerEvent($data, $product, 5, 'restock', now()->subDays(60));
    ledgerEvent($data, $product, -5, 'pos_sale', now()->subDays(55));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30, withStockoutGuard: false);

    expect($result->daysOutOfStock)->toBeNull()
        ->and($result->tier)->toBe(RestockAnalysisResult::TIER_REVIEW);
});

test('a starved product still on the shelf in small quantity is Starved rather than dead-stock', function () {
    $data = velocityVendor();
    // Restocked a small amount recently, but it sat empty for the bulk of
    // the window before that — the guard should still catch it.
    $product = velocityProduct($data, ['stock_quantity' => 2]);
    ledgerEvent($data, $product, 5, 'restock', now()->subDays(60));
    ledgerEvent($data, $product, -5, 'pos_sale', now()->subDays(55));
    ledgerEvent($data, $product, 2, 'restock', now()->subDays(2));

    $result = app(ProductVelocityService::class)->forProduct($product, windowDays: 30);

    expect($result->tier)->toBe(RestockAnalysisResult::TIER_STARVED_REVIEW);
});

test('each vendor only sees its own products and sales', function () {
    $dataA = velocityVendor();
    $dataB = velocityVendor();
    $productA = velocityProduct($dataA, ['stock_quantity' => 5]);
    $productB = velocityProduct($dataB, ['stock_quantity' => 5]);

    deliverOnline($dataA, $productA, 10, now()->subDays(2));
    deliverPos($dataB, $productB, 20, now()->subDays(2));

    $resultsA = app(ProductVelocityService::class)->forVendor($dataA['vendor']->id, windowDays: 30);
    $resultsB = app(ProductVelocityService::class)->forVendor($dataB['vendor']->id, windowDays: 30);

    expect($resultsA)->toHaveCount(1)
        ->and($resultsA->get($productA->id)->dailyVelocity)->toEqualWithDelta(10 / 30, 0.0001)
        ->and($resultsB)->toHaveCount(1)
        ->and($resultsB->get($productB->id)->dailyVelocity)->toEqualWithDelta(20 / 30, 0.0001);
});

test('forVendor can be scoped to a single category', function () {
    $data = velocityVendor();
    $otherCategory = Category::create(['name' => 'Other Category ' . uniqid()]);
    $inCategory = velocityProduct($data, ['stock_quantity' => 5]);
    $outOfCategory = velocityProduct($data, ['stock_quantity' => 5, 'category_id' => $otherCategory->id]);

    $results = app(ProductVelocityService::class)->forVendor($data['vendor']->id, categoryId: $data['category']->id, windowDays: 30);

    expect($results)->toHaveCount(1)
        ->and($results->has($inCategory->id))->toBeTrue()
        ->and($results->has($outOfCategory->id))->toBeFalse();
});
