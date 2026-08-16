<?php

use App\Filament\Vendor\Pages\SalesReport;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\SalesPulseCardProvider;
use App\Services\Reporting\FinancialReportService;

function salesPulseVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Sales Pulse Store ' . uniqid()]);
    $category = Category::create(['name' => 'Sales Pulse Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Pulse Widget',
        'price' => 2000, 'cost_price' => 800, 'stock_quantity' => 100, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function placeOrder(array $data, array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'reference' => 'ORD-SP-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $data['product']->price,
        'status' => 'confirmed', 'payment_method' => 'pay_on_delivery',
    ], $overrides));

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => 1, 'unit_price' => $data['product']->price, 'unit_cost' => $data['product']->cost_price,
    ]);

    return $order;
}

test('headline shows placed and delivered counts for today', function () {
    $data = salesPulseVendor();
    placeOrder($data); // placed, not delivered
    placeOrder($data, ['status' => 'delivered', 'revenue_recognized_at' => now()]);

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toContain('2 placed, 1 delivered today');
});

// Rewritten in Phase 4. Sales Pulse used to be online-only and could be
// checked against FinancialReportService directly. It now counts counter sales
// too, because a card that ignores the till reads as a dead day at a branch
// that traded all morning. The online half must still agree with the Financial
// Report exactly — that shared definition of "recognised" is what the original
// test was protecting, and it still holds.
test('delivered revenue is the online recognised figure plus counter sales', function () {
    $data = salesPulseVendor();
    placeOrder($data, ['status' => 'delivered', 'revenue_recognized_at' => now()]);

    $onlineRevenue = app(FinancialReportService::class)
        ->report($data['vendor']->id, now()->startOfDay(), now()->endOfDay())['revenue'];

    expect($onlineRevenue)->toBeGreaterThan(0);

    // Online only, no till activity: the two must still match to the naira.
    expect((new SalesPulseCardProvider())->summarize($data['vendor']->id)->headline)
        ->toContain('₦' . number_format($onlineRevenue, 2));

    $sale = App\Models\PosSale::create([
        'reference'       => 'POS-'.strtoupper(uniqid()),
        'vendor_id'       => $data['vendor']->id,
        'store_id'        => $data['vendor']->defaultStore->id,
        'cashier_id'      => $data['vendor']->user_id,
        'subtotal'        => 1500,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => 1500,
        'payment_method'  => 'cash',
        'status'          => 'completed',
        'completed_at'    => now(),
    ]);

    expect((new SalesPulseCardProvider())->summarize($data['vendor']->id)->headline)
        ->toContain('₦' . number_format($onlineRevenue + 1500, 2))
        // Which is deliberately NOT what the Financial Report shows: that
        // stays online-only in this phase. See the Phase 4 report.
        ->and($sale->store_id)->not->toBeNull();
});

test('cancelled orders today are the actionable count', function () {
    $data = salesPulseVendor();
    $order = placeOrder($data);
    $order->update(['status' => 'cancelled']);

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->actionableCount)->toBe(1);
});

test('urgency is urgent at or above a 20 percent cancel rate', function () {
    $data = salesPulseVendor();
    $orders = collect(range(1, 5))->map(fn () => placeOrder($data));
    $orders->first()->update(['status' => 'cancelled']); // 1 of 5 = 20%

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT);
});

test('urgency is attention between 10 and 20 percent cancel rate', function () {
    $data = salesPulseVendor();
    $orders = collect(range(1, 10))->map(fn () => placeOrder($data));
    $orders->first()->update(['status' => 'cancelled']); // 1 of 10 = 10%

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_ATTENTION);
});

test('urgency is calm below a 10 percent cancel rate', function () {
    $data = salesPulseVendor();
    collect(range(1, 10))->each(fn () => placeOrder($data)); // 0 cancelled

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_CALM);
});

test('the link points to the sales report page for this vendor', function () {
    $data = salesPulseVendor();

    $summary = (new SalesPulseCardProvider())->summarize($data['vendor']->id);

    expect($summary->link)->toBe(SalesReport::getUrl(panel: 'vendor', tenant: $data['vendor']));
});

test('each vendor only sees its own orders', function () {
    $dataA = salesPulseVendor();
    $dataB = salesPulseVendor();
    placeOrder($dataA);
    placeOrder($dataA);

    $summaryA = (new SalesPulseCardProvider())->summarize($dataA['vendor']->id);
    $summaryB = (new SalesPulseCardProvider())->summarize($dataB['vendor']->id);

    expect($summaryA->headline)->toContain('2 placed')
        ->and($summaryB->headline)->toContain('0 placed');
});
