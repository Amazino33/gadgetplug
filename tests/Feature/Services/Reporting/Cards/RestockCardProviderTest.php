<?php

use App\Filament\Vendor\Pages\RestockReport;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\RestockCardProvider;

function restockCardVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Restock Card Store ' . uniqid()]);
    $category = Category::create(['name' => 'Restock Card Category ' . uniqid()]);

    return compact('owner', 'vendor', 'category');
}

function restockCardProduct(array $data, array $overrides = []): Product
{
    return Product::create(array_merge([
        'vendor_id' => $data['vendor']->id, 'category_id' => $data['category']->id,
        'name' => 'Card Widget ' . uniqid(), 'price' => 1000, 'cost_price' => 400,
        'stock_quantity' => 0, 'status' => 'published', 'created_at' => now()->subYear(),
    ], $overrides));
}

function deliverForCard(array $data, Product $product, int $quantity): void
{
    $order = Order::create([
        'reference' => 'ORD-CARD-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $product->price * $quantity,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => now()->subDays(10),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => $quantity, 'unit_price' => $product->price, 'unit_cost' => $product->cost_price,
    ]);
}

test('a calm headline when nothing needs restocking', function () {
    $data = restockCardVendor();
    restockCardProduct($data, ['stock_quantity' => 5]); // no sales at all -> not "needs restock" (dead/review)

    $summary = (new RestockCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('Nothing needs restocking right now')
        ->and($summary->urgency)->toBe(CardSummary::URGENCY_CALM)
        ->and($summary->actionableCount)->toBe(0);
});

test('urgent tier drives urgency red and names the product', function () {
    $data = restockCardVendor();
    $urgent = restockCardProduct($data, ['name' => 'Zed Urgent Widget', 'stock_quantity' => 3]);
    deliverForCard($data, $urgent, 30); // velocity 1/day, stock 3 -> urgent

    $summary = (new RestockCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT)
        ->and($summary->actionableCount)->toBe(1)
        ->and($summary->headline)->toContain('1 product needs restocking, 1 urgent')
        ->and($summary->headline)->toContain('Zed Urgent Widget');
});

test('reorder-now with no urgent products is attention, not urgent', function () {
    $data = restockCardVendor();
    $reorderNow = restockCardProduct($data, ['stock_quantity' => 8]); // velocity 1/day, cover 8 -> reorder_now
    deliverForCard($data, $reorderNow, 30);

    $summary = (new RestockCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_ATTENTION)
        ->and($summary->actionableCount)->toBe(1);
});

test('the link points to the restock report page for this vendor', function () {
    $data = restockCardVendor();

    $summary = (new RestockCardProvider())->summarize($data['vendor']->id);

    expect($summary->link)->toBe(RestockReport::getUrl(panel: 'vendor', tenant: $data['vendor']));
});

test('each vendor only sees its own restock picture', function () {
    $dataA = restockCardVendor();
    $dataB = restockCardVendor();
    $urgentA = restockCardProduct($dataA, ['stock_quantity' => 3]);
    deliverForCard($dataA, $urgentA, 30);
    restockCardProduct($dataB, ['stock_quantity' => 5]); // nothing needs restocking for B

    $summaryA = (new RestockCardProvider())->summarize($dataA['vendor']->id);
    $summaryB = (new RestockCardProvider())->summarize($dataB['vendor']->id);

    expect($summaryA->urgency)->toBe(CardSummary::URGENCY_URGENT)
        ->and($summaryB->urgency)->toBe(CardSummary::URGENCY_CALM);
});
