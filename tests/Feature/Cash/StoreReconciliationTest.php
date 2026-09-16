<?php

use App\Actions\Cash\LogTillExpenseAction;
use App\Actions\Cash\ResolveCashSubmissionAction;
use App\Actions\Cash\SubmitCashAction;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSalePayment;
use App\Models\User;
use App\Services\Cash\StoreReconciliation;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

function reconContext(): array
{
    $vendor  = cashVendor();
    $store   = $vendor->defaultStore;
    $cashier = User::factory()->create();
    $owner   = User::find($vendor->user_id);

    return compact('vendor', 'store', 'cashier', 'owner');
}

function reconcile(array $ctx, ?string $from = null, ?string $to = null): array
{
    return app(StoreReconciliation::class)->forStore(
        $ctx['store'],
        $from ? Illuminate\Support\Carbon::parse($from) : now()->subMonth(),
        $to ? Illuminate\Support\Carbon::parse($to) : now()->addDay(),
    );
}

test('expected cash counts the cash tender only', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);
    // None of these put a naira in anyone's hands to hand back.
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 30000, 'payment_method' => 'card']);
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 20000, 'payment_method' => 'bank_transfer']);
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 70000, 'payment_method' => 'debt', 'amount_tendered' => 0]);

    expect(reconcile($ctx)['cash']['expected'])->toBe(50000.0);
});

test('the cash leg of a split sale is expected back, the card leg is not', function () {
    $ctx = reconContext();

    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, [
        'total' => 100000, 'payment_method' => 'split', 'amount_tendered' => 40000, 'change_given' => 0,
    ]);

    PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => 'cash', 'amount' => 40000]);
    PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => 'card', 'amount' => 60000]);

    expect(reconcile($ctx)['cash']['expected'])->toBe(40000.0);
});

test('declared till spending nets off what is expected back', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    app(LogTillExpenseAction::class)->execute(
        loggedBy: $ctx['cashier'],
        store:    $ctx['store'],
        amount:   7500,
        reason:   'Diesel for the generator',
    );

    $recon = reconcile($ctx);

    // The money left for the business, so it is not a shortage.
    expect($recon['cash']['takings'])->toBe(50000.0)
        ->and($recon['cash']['till_expenses'])->toBe(7500.0)
        ->and($recon['cash']['expected'])->toBe(42500.0)
        ->and($recon['outstanding']['true_shortage'])->toBe(0.0);
});

test('an expense with no reason is refused', function () {
    $ctx = reconContext();

    expect(fn () => app(LogTillExpenseAction::class)->execute(
        loggedBy: $ctx['cashier'], store: $ctx['store'], amount: 5000, reason: '',
    ))->toThrow(RuntimeException::class);
});

test('cash handed over but not yet acknowledged sits in its own bucket, not in shortage', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    app(SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );

    $out = reconcile($ctx)['outstanding'];

    expect($out['pending_confirmation'])->toBe(50000.0)
        ->and($out['unsubmitted'])->toBe(0.0)
        ->and($out['true_shortage'])->toBe(0.0);
});

test('a confirmed handover clears the gap entirely', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );

    app(ResolveCashSubmissionAction::class)->confirm($submission, $ctx['owner']);

    $recon = reconcile($ctx);

    expect($recon['cash']['shortage'])->toBe(0.0)
        ->and($recon['outstanding']['true_shortage'])->toBe(0.0);
});

test('a disputed handover leaves only the contested difference unexplained', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );

    // Handed over as 50,000; only 45,000 arrived.
    app(ResolveCashSubmissionAction::class)->dispute(
        $submission, $ctx['owner'], 'Counted 45,000 in the envelope', 45000,
    );

    $out = reconcile($ctx)['outstanding'];

    expect($out['disputed'])->toBe(5000.0)
        ->and($out['true_shortage'])->toBe(5000.0);
});

test('cash never handed over is not yet a shortage inside the grace window', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $out = reconcile($ctx)['outstanding'];

    // The period runs to tomorrow, so nobody is late yet.
    expect($out['unsubmitted'])->toBe(50000.0)
        ->and($out['true_shortage'])->toBe(0.0);
});

test('cash never handed over becomes a shortage once the grace window passes', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, [
        'total' => 50000, 'completed_at' => now()->subDays(10),
    ]);

    $out = reconcile($ctx, from: now()->subDays(20)->toDateTimeString(), to: now()->subDays(9)->toDateTimeString())['outstanding'];

    expect($out['unsubmitted'])->toBe(50000.0)
        ->and($out['true_shortage'])->toBe(50000.0);
});

test('a voided cash sale is reported as withdrawn rather than silently absent', function () {
    $ctx = reconContext();

    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);
    voidSaleInTest($sale, 'Rang twice');

    $recon = reconcile($ctx);

    expect($recon['cash']['expected'])->toBe(0.0)
        ->and($recon['reversals']['voided_cash'])->toBe(50000.0)
        ->and($recon['reversals']['voided_cash_count'])->toBe(1);
});

test('voiding a split sale only withdraws the cash leg, not the card half', function () {
    $ctx = reconContext();

    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, [
        'total' => 100000, 'payment_method' => 'split', 'amount_tendered' => 40000, 'change_given' => 0,
    ]);
    PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => 'cash', 'amount' => 40000]);
    PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => 'card', 'amount' => 60000]);

    voidSaleInTest($sale, 'Wrong customer');

    // Only 40,000 was ever in the drawer. Reporting the full 100,000 here would
    // overstate what the void actually took out of expected cash.
    expect(reconcile($ctx)['reversals']['voided_cash'])->toBe(40000.0);
});

test('cash is broken down per branch, and per cashier within a branch', function () {
    $ctx = reconContext();

    $second = App\Models\Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    $ada = User::factory()->create(['name' => 'Ada']);

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);
    cashSale($ctx['vendor'], $ctx['store'], $ada->id, ['total' => 30000]);
    cashSale($ctx['vendor'], $second, $ada->id, ['total' => 20000]);

    $branches = app(StoreReconciliation::class)
        ->byBranch($ctx['vendor'], now()->subMonth(), now()->addDay());

    expect($branches)->toHaveCount(2);

    $main = $branches->firstWhere('store_id', $ctx['store']->id);
    $other = $branches->firstWhere('store_id', $second->id);

    expect($main['collected'])->toBe(80000.0)
        ->and($other['collected'])->toBe(20000.0);

    // The names are the point: "the branch is short" is not actionable.
    // Compared per name rather than as a whole array, since the list is ordered
    // by who owes most and that order is deliberate.
    expect($main['cashiers'])->toHaveCount(2)
        ->and($main['cashiers']->firstWhere('name', 'Ada')['collected'])->toBe(30000.0)
        ->and($main['cashiers']->firstWhere('name', $ctx['cashier']->name)['collected'])->toBe(50000.0);

    // The same person at a different branch is a different row.
    expect($other['cashiers']->firstWhere('name', 'Ada')['collected'])->toBe(20000.0);
});

test('a cashier who has handed cash over shows as owing nothing', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    app(SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );

    $branch = app(StoreReconciliation::class)
        ->byBranch($ctx['vendor'], now()->subMonth(), now()->addDay())
        ->firstWhere('store_id', $ctx['store']->id);

    $cashier = $branch['cashiers']->first();

    expect($cashier['collected'])->toBe(50000.0)
        ->and($cashier['handed_over'])->toBe(50000.0)
        ->and($cashier['outstanding'])->toBe(0.0);
});

test('the total balance is everything the branch holds or is owed', function () {
    $ctx = reconContext();

    // 20 units on the shelf at 6,000 cost = 120,000 tied up in stock.
    $product = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Balance Widget', 'price' => 10000, 'cost_price' => 6000,
        'stock_quantity' => 20, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    App\Models\ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
        ['quantity' => 20, 'reserved' => 0],
    );

    // 50,000 collected and never handed over.
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    // A customer owes 30,000.
    $customer = App\Models\PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Debtor', 'phone' => '0803' . rand(1000000, 9999999),
    ]);
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $customer->id, 'vendor_id' => $ctx['vendor']->id,
        'direction' => 'charge', 'amount' => 30000, 'store_id' => $ctx['store']->id,
        'created_by' => $ctx['owner']->id, 'occurred_at' => now()->toDateString(), 'description' => 'Credit sale',
    ]);

    $p = reconcile($ctx)['position'];

    expect($p['stock_at_cost'])->toBe(120000.0)
        ->and($p['cash_outstanding'])->toBe(50000.0)
        ->and($p['customer_debt'])->toBe(30000.0);

    // The total is the sum of the parts, and says so on the page.
    expect($p['total_balance'])->toBe(
        $p['stock_at_cost'] + $p['cash_outstanding'] + $p['customer_debt']
        + $p['pickings_retail'] + $p['staff_debts']
    );
});

test('handing the cash over moves it out of the balance, it does not vanish', function () {
    $ctx = reconContext();

    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    expect(reconcile($ctx)['position']['cash_outstanding'])->toBe(50000.0);

    $submission = app(SubmitCashAction::class)->execute(
        submitter: $ctx['cashier'], receiver: $ctx['owner'], store: $ctx['store'], amount: 50000,
    );
    app(ResolveCashSubmissionAction::class)->confirm($submission, $ctx['owner']);

    // Confirmed means the business has it, so the branch is no longer holding it.
    expect(reconcile($ctx)['position']['cash_outstanding'])->toBe(0.0);
});

test('stock with no cost price is left out of the value and counted instead', function () {
    $ctx = reconContext();

    $product = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Uncosted Widget', 'price' => 9000, 'cost_price' => null,
        'stock_quantity' => 7, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    App\Models\ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
        ['quantity' => 7, 'reserved' => 0],
    );

    $p = reconcile($ctx)['position'];

    // Valued at nothing would understate the branch invisibly; this says so.
    expect($p['stock_at_cost'])->toBe(0.0)
        ->and($p['stock_uncosted'])->toBe(1);
});

test('debt payments clear the oldest charge first, so aging is real', function () {
    $ctx = reconContext();

    $customer = App\Models\PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '0803' . rand(1000000, 9999999),
    ]);

    foreach ([['2026-06-01', 30000], ['2026-09-10', 20000]] as [$date, $amount]) {
        PosCustomerLedgerEntry::create([
            'pos_customer_id' => $customer->id, 'vendor_id' => $ctx['vendor']->id,
            'direction' => 'charge', 'amount' => $amount, 'store_id' => $ctx['store']->id,
            'created_by' => $ctx['owner']->id, 'occurred_at' => $date, 'description' => 'Credit sale',
        ]);
    }

    // Pays 30,000 — enough to clear the June charge exactly.
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $customer->id, 'vendor_id' => $ctx['vendor']->id,
        'direction' => 'payment', 'amount' => -30000, 'store_id' => $ctx['store']->id,
        'created_by' => $ctx['owner']->id, 'occurred_at' => '2026-09-12', 'description' => 'Part payment',
    ]);

    $aged = app(CustomerDebtService::class)->aged(
        $ctx['vendor']->id, $ctx['store']->id, Illuminate\Support\Carbon::parse('2026-09-16'),
    );

    // What is left is the recent charge, not the old one — so this customer is
    // six days late, not three months.
    expect($aged['total'])->toBe(20000.0)
        ->and($aged['debtors']->first()['days_oldest'])->toBe(6)
        ->and($aged['buckets']['current'])->toBe(20000.0)
        ->and($aged['buckets']['over_60'])->toBe(0.0);
});
