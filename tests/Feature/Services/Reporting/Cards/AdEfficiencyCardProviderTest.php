<?php

use App\Filament\Vendor\Resources\Expenses\ExpenseResource;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\AdEfficiencyCardProvider;
use App\Services\Reporting\Cards\CardSummary;

function adCardVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Ad Card Store ' . uniqid()]);
    $category = Category::create(['name' => 'Ad Card Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Ad Widget',
        'price' => 10000, 'cost_price' => 4000, 'stock_quantity' => 100, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function deliverForAdCard(array $data, int $quantity): void
{
    $order = Order::create([
        'reference' => 'ORD-AD-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $data['product']->price * $quantity,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => $quantity, 'unit_price' => $data['product']->price, 'unit_cost' => $data['product']->cost_price,
    ]);
}

function spendOnAds(array $data, float $amount): void
{
    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => $amount,
        'incurred_at' => now(), 'posted_at' => now(),
    ]);
}

test('headline shows this month\'s ad spend', function () {
    $data = adCardVendor();
    spendOnAds($data, 1500);

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('₦1,500.00 ad spend this month');
});

test('comparison shows the ratio to delivered revenue', function () {
    $data = adCardVendor();
    deliverForAdCard($data, 1); // revenue 10,000
    spendOnAds($data, 1500); // 15%

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->comparison)->toBe('15.0% of delivered revenue');
});

test('urgency is calm below the attention threshold', function () {
    $data = adCardVendor();
    deliverForAdCard($data, 1); // revenue 10,000
    spendOnAds($data, 1000); // 10%

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_CALM);
});

test('urgency is attention between 15 and 25 percent', function () {
    $data = adCardVendor();
    deliverForAdCard($data, 1); // revenue 10,000
    spendOnAds($data, 1500); // 15%

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_ATTENTION);
});

test('urgency is urgent at or above 25 percent', function () {
    $data = adCardVendor();
    deliverForAdCard($data, 1); // revenue 10,000
    spendOnAds($data, 2500); // 25%

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT);
});

test('urgency is urgent when there is ad spend but zero delivered revenue', function () {
    $data = adCardVendor();
    spendOnAds($data, 500); // spend, but nothing delivered this month

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT)
        ->and($summary->comparison)->toBe('No delivered revenue yet to compare against');
});

test('no ad spend and no revenue is calm with an honest message, not a fake ratio', function () {
    $data = adCardVendor();

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_CALM)
        ->and($summary->comparison)->toBe('No ad spend recorded this month');
});

test('the link points to the expenses resource for this vendor', function () {
    $data = adCardVendor();

    $summary = (new AdEfficiencyCardProvider())->summarize($data['vendor']->id);

    expect($summary->link)->toBe(ExpenseResource::getUrl('index', panel: 'vendor', tenant: $data['vendor']));
});

test('each vendor only sees its own ad spend and revenue', function () {
    $dataA = adCardVendor();
    $dataB = adCardVendor();
    spendOnAds($dataA, 1500);

    $summaryA = (new AdEfficiencyCardProvider())->summarize($dataA['vendor']->id);
    $summaryB = (new AdEfficiencyCardProvider())->summarize($dataB['vendor']->id);

    expect($summaryA->headline)->toBe('₦1,500.00 ad spend this month')
        ->and($summaryB->headline)->toBe('₦0.00 ad spend this month');
});
