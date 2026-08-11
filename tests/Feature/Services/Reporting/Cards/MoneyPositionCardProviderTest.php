<?php

use App\Filament\Vendor\Pages\FinancialReport;
use App\Models\Category;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\Cards\CardSummary;
use App\Services\Reporting\Cards\MoneyPositionCardProvider;

function moneyCardVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Money Card Store ' . uniqid()]);
    $category = Category::create(['name' => 'Money Card Category ' . uniqid()]);

    // VendorObserver already seeds a zero-balance Bank/Cash account for every
    // new vendor (FinancialAccounts::seedFor) — update those rather than
    // creating duplicates, which would leave balance() reading whichever one
    // ->first() happens to return.
    FinancialAccount::where('vendor_id', $vendor->id)->where('type', 'bank')->update(['opening_balance' => 15000]);
    FinancialAccount::where('vendor_id', $vendor->id)->where('type', 'cash')->update(['opening_balance' => 5000]);

    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id, 'name' => 'Money Widget',
        'price' => 5000, 'cost_price' => 2000, 'stock_quantity' => 50, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'category', 'product');
}

function deliverForMoneyCard(array $data, int $quantity, $recognizedAt): void
{
    $order = Order::create([
        'reference' => 'ORD-MC-' . uniqid(), 'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '0804', 'shipping_address' => 'Uyo', 'total_amount' => $data['product']->price * $quantity,
        'status' => 'delivered', 'payment_method' => 'pay_on_delivery', 'revenue_recognized_at' => $recognizedAt,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $data['product']->id, 'vendor_id' => $data['vendor']->id,
        'quantity' => $quantity, 'unit_price' => $data['product']->price, 'unit_cost' => $data['product']->cost_price,
    ]);
}

test('headline shows the live bank and cash balances', function () {
    $data = moneyCardVendor();

    $summary = (new MoneyPositionCardProvider())->summarize($data['vendor']->id);

    expect($summary->headline)->toBe('Bank ₦15,000.00 · Cash ₦5,000.00');
});

test('urgency is urgent when this month\'s net profit is negative', function () {
    $data = moneyCardVendor();
    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 5000,
        'incurred_at' => now(), 'posted_at' => now(),
    ]);

    $summary = (new MoneyPositionCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_URGENT);
});

test('urgency is attention when profit is positive but fell from last month', function () {
    $data = moneyCardVendor();
    deliverForMoneyCard($data, 3, now()->subMonthNoOverflow()); // last month: 3*3000 profit = 9000
    deliverForMoneyCard($data, 1, now()); // this month: 1*3000 profit = 3000

    $summary = (new MoneyPositionCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_ATTENTION)
        ->and($summary->comparisonDirection)->toBe('down');
});

test('urgency is calm when profit improved on last month', function () {
    $data = moneyCardVendor();
    deliverForMoneyCard($data, 1, now()->subMonthNoOverflow()); // last month profit 3000
    deliverForMoneyCard($data, 3, now()); // this month profit 9000

    $summary = (new MoneyPositionCardProvider())->summarize($data['vendor']->id);

    expect($summary->urgency)->toBe(CardSummary::URGENCY_CALM)
        ->and($summary->comparisonDirection)->toBe('up');
});

test('the link points to the financial report page for this vendor', function () {
    $data = moneyCardVendor();

    $summary = (new MoneyPositionCardProvider())->summarize($data['vendor']->id);

    expect($summary->link)->toBe(FinancialReport::getUrl(panel: 'vendor', tenant: $data['vendor']));
});

test('each vendor only sees its own money position', function () {
    $dataA = moneyCardVendor();
    $dataB = moneyCardVendor();

    $summaryA = (new MoneyPositionCardProvider())->summarize($dataA['vendor']->id);
    $summaryB = (new MoneyPositionCardProvider())->summarize($dataB['vendor']->id);

    // Both stores were seeded identically, but each provider call must hit
    // only its own vendor's FinancialAccount rows, not sum across both.
    expect($summaryA->headline)->toBe('Bank ₦15,000.00 · Cash ₦5,000.00')
        ->and($summaryB->headline)->toBe('Bank ₦15,000.00 · Cash ₦5,000.00');
});
