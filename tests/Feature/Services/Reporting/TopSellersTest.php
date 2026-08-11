<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\ProductVelocityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function topSellerVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Top Seller Store ' . uniqid()]);
    $category = Category::create(['name' => 'Top Seller Category ' . uniqid()]);

    return compact('owner', 'vendor', 'category');
}

function topSellerProduct(array $data, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id' => $data['vendor']->id, 'category_id' => $data['category']->id,
        'name' => 'Seller Widget ' . uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 50, 'status' => 'published',
    ], $overrides));
}

function deliverTopSellerOnline(array $data, Product $product, int $quantity, $recognizedAt): void
{
    $order = Order::create([
        'reference' => 'ORD-TS-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $product->price * $quantity,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => $recognizedAt,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);
}

function deliverTopSellerPos(array $data, Product $product, int $quantity, $completedAt, string $status = 'completed'): void
{
    $sale = PosSale::create([
        'reference' => 'POS-TS-' . uniqid(), 'vendor_id' => $data['vendor']->id, 'cashier_id' => $data['owner']->id,
        'subtotal' => $product->price * $quantity, 'total' => $product->price * $quantity,
        'payment_method' => 'cash', 'status' => $status, 'completed_at' => $completedAt,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'unit_price' => $product->price, 'quantity' => $quantity, 'total' => $product->price * $quantity,
    ]);
}

test('ranks products by units sold, highest first, combining online and POS', function () {
    $data = topSellerVendor();
    $slow = topSellerProduct($data);
    $fast = topSellerProduct($data);

    deliverTopSellerOnline($data, $slow, 2, now()->subDays(2));
    deliverTopSellerOnline($data, $fast, 5, now()->subDays(2));
    deliverTopSellerPos($data, $fast, 10, now()->subDays(1)); // fast: 5 + 10 = 15 total

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, now()->subDays(7), now());

    expect($rows->first()->product->id)->toBe($fast->id)
        ->and($rows->first()->unitsSold)->toBe(15)
        ->and($rows->last()->product->id)->toBe($slow->id)
        ->and($rows->last()->unitsSold)->toBe(2);
});

test('revenue and daily velocity are computed correctly for the period', function () {
    $data = topSellerVendor();
    $product = topSellerProduct($data, ['price' => 2000]);
    deliverTopSellerOnline($data, $product, 10, now()->subDays(3));

    // A 5-day period (inclusive on both ends): 10 units / 5 days = 2/day.
    $from = now()->subDays(4)->startOfDay();
    $to = now()->endOfDay();

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, $from, $to);

    expect($rows->first()->revenue)->toBe(20000.0)
        ->and($rows->first()->dailyVelocity)->toEqualWithDelta(2.0, 0.01);
});

test('a voided POS sale and a cancelled online order are excluded', function () {
    $data = topSellerVendor();
    $product = topSellerProduct($data);
    deliverTopSellerPos($data, $product, 10, now()->subDay(), status: 'voided');

    Order::create([
        'reference' => 'ORD-TS-CANCEL', 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => 1000,
        'status' => 'cancelled', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => null,
    ]);

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, now()->subDays(7), now());

    expect($rows)->toBeEmpty();
});

test('sales outside the requested period are not counted', function () {
    $data = topSellerVendor();
    $product = topSellerProduct($data);
    deliverTopSellerOnline($data, $product, 5, now()->subDays(30)); // outside a 7-day window

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, now()->subDays(7), now());

    expect($rows)->toBeEmpty();
});

test('the category filter scopes the ranking to one category', function () {
    $data = topSellerVendor();
    $otherCategory = Category::create(['name' => 'Other Top Seller Category ' . uniqid()]);
    $inCategory = topSellerProduct($data);
    $outOfCategory = topSellerProduct($data, ['category_id' => $otherCategory->id]);

    deliverTopSellerOnline($data, $inCategory, 5, now()->subDay());
    deliverTopSellerOnline($data, $outOfCategory, 5, now()->subDay());

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, now()->subDays(7), now(), categoryId: $data['category']->id);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->product->id)->toBe($inCategory->id);
});

test('the limit caps how many rows come back', function () {
    $data = topSellerVendor();
    foreach (range(1, 5) as $i) {
        $product = topSellerProduct($data);
        deliverTopSellerOnline($data, $product, $i, now()->subDay());
    }

    $rows = app(ProductVelocityService::class)->topSellers($data['vendor']->id, now()->subDays(7), now(), limit: 2);

    expect($rows)->toHaveCount(2);
});

test('each vendor only sees its own top sellers', function () {
    $dataA = topSellerVendor();
    $dataB = topSellerVendor();
    $productA = topSellerProduct($dataA);
    deliverTopSellerOnline($dataA, $productA, 20, now()->subDay());

    $rowsA = app(ProductVelocityService::class)->topSellers($dataA['vendor']->id, now()->subDays(7), now());
    $rowsB = app(ProductVelocityService::class)->topSellers($dataB['vendor']->id, now()->subDays(7), now());

    expect($rowsA)->toHaveCount(1)
        ->and($rowsB)->toBeEmpty();
});
