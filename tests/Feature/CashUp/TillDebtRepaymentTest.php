<?php

use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosDebtPayment;
use App\Models\Store;
use App\Models\User;
use App\Services\Cash\CashUpExpectation;
use App\Services\Pos\CustomerDebtService;
use App\Support\Pos\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/** A till signed in, with a customer who owes something. */
function debtRepaymentContext(float $owed = 10000.0): array
{
    $ctx = cashUpContext();
    $ctx['vendor']->users()->syncWithoutDetaching([$ctx['cashier']->id]);

    $ctx['customer'] = PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '08031234567',
    ]);

    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $ctx['customer']->id,
        'vendor_id'       => $ctx['vendor']->id,
        'direction'       => 'charge',
        'amount'          => $owed,
        'store_id'        => $ctx['store']->id,
        'created_by'      => $ctx['owner']->id,
        'occurred_at'     => now()->subDays(3)->toDateString(),
        'description'     => 'Credit sale — seed',
    ]);

    Sanctum::actingAs($ctx['cashier']);

    return $ctx;
}

function collectRepayment(array $ctx, array $over = [])
{
    return test()->postJson("/api/pos/customers/{$ctx['customer']->id}/repayments", array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'amount'    => 4000,
        'method'    => 'cash',
    ], $over));
}

// tillSale() is declared in TillExpenseTest.php — Pest loads every test file
// into one global function namespace, and a second identical declaration
// here would collide with it the moment both files load in the same run.

// ── Recording it at the counter ──────────────────────────────────────────────

test('a cashier collects a debt repayment from any branch', function () {
    $ctx = debtRepaymentContext();

    collectRepayment($ctx)->assertCreated();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);

    $payment = PosDebtPayment::first();

    expect((float) $payment->amount)->toBe(4000.0)
        ->and($payment->method)->toBe('cash')
        ->and($payment->collected_by)->toBe($ctx['cashier']->id)
        ->and($payment->store_id)->toBe($ctx['store']->id)
        ->and($payment->collected_at->toDateString())->toBe(BusinessDate::today());
});

test('a repayment at a different branch than the debt was run up at still works', function () {
    $ctx = debtRepaymentContext();

    // The debt was charged at $ctx['store']. This cashier collects it while
    // standing at a second, unrelated branch — "from any branch" is the point.
    // TillStore::resolve() reads a cashier's own store assignment, so they are
    // moved to it rather than merely added to it: with two assignments the
    // resolver would fall back to the vendor default instead of picking one.
    $elsewhere = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Elsewhere Branch']);
    $ctx['cashier']->stores()->sync([$elsewhere->id]);

    collectRepayment($ctx)->assertCreated();

    $payment = PosDebtPayment::first();

    expect($payment->store_id)->toBe($elsewhere->id)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);
});

test('it credits the bank account when collected by card or transfer', function () {
    $ctx = debtRepaymentContext();

    collectRepayment($ctx, ['method' => 'bank_transfer'])->assertCreated();

    $bank = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'bank')->firstOrFail();
    $cash = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->firstOrFail();

    expect((float) FinancialLedgerEntry::where('financial_account_id', $bank->id)->where('direction', 'in')->sum('amount'))->toBe(4000.0)
        ->and((float) FinancialLedgerEntry::where('financial_account_id', $cash->id)->where('direction', 'in')->sum('amount'))->toBe(0.0);
});

test('collecting more than is owed is refused', function () {
    $ctx = debtRepaymentContext(owed: 3000);

    collectRepayment($ctx, ['amount' => 3001])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'owes'));

    expect(PosDebtPayment::count())->toBe(0)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(3000.0);
});

test('nothing and negatives are refused', function () {
    $ctx = debtRepaymentContext();

    collectRepayment($ctx, ['amount' => 0])->assertStatus(422);
    collectRepayment($ctx, ['amount' => -500])->assertStatus(422);
});

test('an unknown method is refused', function () {
    $ctx = debtRepaymentContext();

    collectRepayment($ctx, ['method' => 'store_credit'])->assertStatus(422);
});

// ── What it does to the drawer ───────────────────────────────────────────────

test('a cash repayment raises what the drawer should hold', function () {
    $ctx = debtRepaymentContext();
    tillSale($ctx, ['total' => 80000]);
    collectRepayment($ctx, ['amount' => 4000])->assertCreated();

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id,
        storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id,
        businessDate: BusinessDate::today(),
        openingFloat: 20000,
    );

    // 20,000 float + 80,000 sales + 4,000 debt collected in cash.
    expect($breakdown->expectedCash)->toBe(104000.0);

    $line = collect($breakdown->cashLines)->firstWhere('key', 'debt_repayments');
    expect($line['amount'])->toBe(4000.0)
        ->and($line['label'])->toBe('Debt repayments collected');
});

test('a card repayment raises the terminal leg instead of cash', function () {
    $ctx = debtRepaymentContext();
    collectRepayment($ctx, ['amount' => 4000, 'method' => 'card'])->assertCreated();

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id,
        storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id,
        businessDate: BusinessDate::today(),
        openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0)
        ->and($breakdown->expectedTerminal)->toBe(4000.0);

    $line = collect($breakdown->terminalLines)->firstWhere('key', 'debt_repayments');
    expect($line['amount'])->toBe(4000.0);
});

test('another cashier collecting a repayment does not raise this drawer', function () {
    $ctx = debtRepaymentContext();
    $mate = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$mate->id]);

    PosDebtPayment::create([
        'pos_customer_id'              => $ctx['customer']->id,
        'vendor_id'                    => $ctx['vendor']->id,
        'store_id'                     => $ctx['store']->id,
        'collected_by'                 => $mate->id,
        'pos_customer_ledger_entry_id' => PosCustomerLedgerEntry::create([
            'pos_customer_id' => $ctx['customer']->id, 'vendor_id' => $ctx['vendor']->id,
            'direction' => 'payment', 'amount' => -5000, 'occurred_at' => BusinessDate::today(),
        ])->id,
        'method'       => 'cash',
        'amount'       => 5000,
        'collected_at' => BusinessDate::today(),
    ]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id, storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id, businessDate: BusinessDate::today(), openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0);
});

test('yesterday repayment does not follow the cashier into today', function () {
    $ctx = debtRepaymentContext();

    PosDebtPayment::create([
        'pos_customer_id'              => $ctx['customer']->id,
        'vendor_id'                    => $ctx['vendor']->id,
        'store_id'                     => $ctx['store']->id,
        'collected_by'                 => $ctx['cashier']->id,
        'pos_customer_ledger_entry_id' => PosCustomerLedgerEntry::create([
            'pos_customer_id' => $ctx['customer']->id, 'vendor_id' => $ctx['vendor']->id,
            'direction' => 'payment', 'amount' => -5000, 'occurred_at' => now()->subDay()->toDateString(),
        ])->id,
        'method'       => 'cash',
        'amount'       => 5000,
        'collected_at' => now()->subDay()->toDateString(),
    ]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id, storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id, businessDate: BusinessDate::today(), openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0);
});
