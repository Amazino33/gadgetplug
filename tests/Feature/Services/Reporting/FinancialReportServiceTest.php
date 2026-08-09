<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reportVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Report Store ' . uniqid()]);
    $category = Category::create(['name' => 'Report Category ' . uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Report Product', 'price' => 5000, 'cost_price' => 3000,
        'stock_quantity' => 20, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function reportOrder(array $data, array $overrides = []): Order
{
    $order = Order::create(array_merge([
        'reference' => 'ORD-RPT-' . uniqid(),
        'customer_name' => 'Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 5000, 'status' => 'delivered', 'payment_method' => 'pay_on_delivery',
        'revenue_recognized_at' => now(),
    ], $overrides));

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => 1, 'unit_price' => 5000, 'unit_cost' => 3000,
    ]);

    return $order;
}

test('a cancelled order never counts as revenue, even with items on it', function () {
    $data = reportVendor();
    reportOrder($data, ['status' => 'cancelled', 'revenue_recognized_at' => null]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['revenue'])->toBe(0.0)
        ->and($report['product_cost'])->toBe(0.0);
});

test('product cost uses the snapshotted unit_cost, not today\'s cost_price', function () {
    $data = reportVendor();
    $data['product']->update(['cost_price' => 9999]); // changed after the sale
    reportOrder($data); // unit_cost snapshotted at 3000 in the helper

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['product_cost'])->toBe(3000.0)
        ->and($report['cost_is_estimated'])->toBeFalse();
});

test('product cost falls back to current cost_price and flags the figure when unit_cost is missing', function () {
    $data = reportVendor();
    $order = reportOrder($data);
    $order->items()->update(['unit_cost' => null]);
    $data['product']->update(['cost_price' => 3500]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['product_cost'])->toBe(3500.0)
        ->and($report['cost_is_estimated'])->toBeTrue();
});

test('a restock-heavy period is never a loss from procurement spend alone', function () {
    $data = reportVendor();
    $supplier = Supplier::create(['vendor_id' => $data['vendor']->id, 'name' => 'Supplier']);

    // A large procurement, fully paid — but procurement spend itself is never
    // a P&L line (unsold stock is inventory, not expense).
    Procurement::create([
        'vendor_id' => $data['vendor']->id, 'supplier_id' => $supplier->id, 'created_by' => $data['owner']->id,
        'status' => 'approved', 'total_cost' => 500000, 'amount_paid' => 500000,
        'payment_status' => 'full', 'payment_method' => 'bank_transfer',
    ]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['revenue'])->toBe(0.0)
        ->and($report['net_profit'])->toBe(0.0);
});

test('inbound logistics and outbound delivery each appear once, as their own lines, never inside product cost', function () {
    $data = reportVendor();
    $supplier = Supplier::create(['vendor_id' => $data['vendor']->id, 'name' => 'Supplier']);
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();

    $procurement = Procurement::create([
        'vendor_id' => $data['vendor']->id, 'supplier_id' => $supplier->id, 'created_by' => $data['owner']->id,
        'status' => 'approved', 'total_cost' => 10000, 'amount_paid' => 10000,
        'payment_status' => 'full', 'payment_method' => 'cash',
    ]);
    $procurement->legs()->create([
        'route_label' => 'Supplier to store', 'amount' => 1500, 'sort_order' => 0,
        'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);

    $order = reportOrder($data);
    $order->update(['delivery_cost' => 800, 'financial_account_id' => $account->id, 'posted_at' => now()]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['inbound_logistics'])->toBe(1500.0)
        ->and($report['outbound_delivery'])->toBe(800.0)
        ->and($report['product_cost'])->toBe(3000.0) // unchanged by either logistics figure
        ->and($report['net_profit'])->toBe(5000.0 - 3000.0 - 1500.0 - 800.0);
});

test('expense categories each contribute their own line, filtered by when they were posted, not incurred', function () {
    $data = reportVendor();
    $account = FinancialAccount::where('vendor_id', $data['vendor']->id)->first();

    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 2000,
        'incurred_at' => now()->subWeeks(3)->toDateString(), // incurred outside the range...
        'financial_account_id' => $account->id, 'posted_at' => now(), // ...but posted inside it
    ]);
    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'logistics_other', 'amount' => 1200,
        'incurred_at' => now()->toDateString(), 'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);
    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 900,
        'incurred_at' => now()->toDateString(), 'financial_account_id' => $account->id, 'posted_at' => now(),
    ]);
    // Never posted — must not count at all, cash-basis.
    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 50000,
        'incurred_at' => now()->toDateString(),
    ]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['advertising'])->toBe(2000.0)
        ->and($report['other_logistics'])->toBe(1200.0)
        ->and($report['other_expenses'])->toBe(900.0);
});

test('bank and cash balances match the ledger\'s own derived math', function () {
    $data = reportVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    $bank->update(['opening_balance' => 10000]);
    \App\Services\FinancialLedger::postEntry($bank, 'in', 3000, description: 'Manual credit');
    \App\Services\FinancialLedger::postEntry($bank, 'out', 500, description: 'Manual debit');

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['balances']['bank'])->toBe($bank->fresh()->balance())
        ->and($report['balances']['bank'])->toBe(12500.0)
        ->and($report['balances']['total_liquid'])->toBe($report['balances']['bank'] + $report['balances']['cash']);
});

test('date range boundaries are inclusive on both ends', function () {
    $data = reportVendor();

    reportOrder($data, ['revenue_recognized_at' => now()->startOfDay()]);
    reportOrder($data, ['revenue_recognized_at' => now()->endOfDay()]);
    reportOrder($data, ['revenue_recognized_at' => now()->subDays(2)]); // outside range

    $report = app(FinancialReportService::class)->report(
        $data['vendor']->id,
        now()->startOfDay(),
        now()->endOfDay(),
    );

    expect($report['revenue'])->toBe(10000.0); // two in-range orders, not the third
});

test('inventory value is stock quantity times cost price, and initial capital surfaces in balances', function () {
    $data = reportVendor();
    $data['vendor']->update(['initial_capital' => 250000]);
    $data['product']->update(['stock_quantity' => 20, 'cost_price' => 3000]);

    $report = app(FinancialReportService::class)->report($data['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['balances']['inventory_value'])->toBe(60000.0)
        ->and($report['balances']['initial_capital'])->toBe(250000.0);
});

test('each vendor\'s report only reflects its own data', function () {
    $dataA = reportVendor();
    $dataB = reportVendor();
    reportOrder($dataA);
    reportOrder($dataB);
    reportOrder($dataB);

    $reportA = app(FinancialReportService::class)->report($dataA['vendor']->id, now()->subDay(), now()->addDay());
    $reportB = app(FinancialReportService::class)->report($dataB['vendor']->id, now()->subDay(), now()->addDay());

    expect($reportA['revenue'])->toBe(5000.0)
        ->and($reportB['revenue'])->toBe(10000.0);
});
