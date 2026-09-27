<?php

use App\Actions\Pos\RecordDebtRepaymentAction;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosDebtPayment;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// repaymentContext() (tests/Pest.php) gives a vendor owing 10,000 on a
// 'cash' FinancialAccount already set up. A 'bank' account is added here for
// the card/transfer cases, since repaymentContext() only seeds cash.

function withBankAccount(array $ctx): array
{
    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Bank', 'type' => 'bank',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    return $ctx;
}

it('reduces what the customer owes and credits the cash account', function () {
    $ctx = repaymentContext();

    $payment = app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 4000, method: 'cash', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0)
        ->and($payment)->toBeInstanceOf(PosDebtPayment::class)
        ->and($payment->method)->toBe('cash')
        ->and((float) $payment->amount)->toBe(4000.0)
        ->and($payment->store_id)->toBe($ctx['store']->id)
        ->and($payment->collected_by)->toBe($ctx['owner']->id);

    $account = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->first();
    $entry   = FinancialLedgerEntry::where('financial_account_id', $account->id)->where('direction', 'in')->first();

    expect((float) $entry->amount)->toBe(4000.0);
});

it('credits the bank account instead when paid by card or transfer', function () {
    $ctx = withBankAccount(repaymentContext());

    $payment = app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 3000, method: 'bank_transfer', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    $bank = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'bank')->first();
    $cash = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->first();

    expect($payment->method)->toBe('bank_transfer')
        ->and((float) FinancialLedgerEntry::where('financial_account_id', $bank->id)->where('direction', 'in')->sum('amount'))->toBe(3000.0)
        ->and((float) FinancialLedgerEntry::where('financial_account_id', $cash->id)->where('direction', 'in')->sum('amount'))->toBe(0.0);
});

it('links the payment row back to the ledger entry it produced', function () {
    $ctx = repaymentContext();

    $payment = app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 1000, method: 'cash', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    $ledger = PosCustomerLedgerEntry::where('direction', 'payment')->firstOrFail();

    expect($payment->pos_customer_ledger_entry_id)->toBe($ledger->id);
});

it('refuses to collect more than is actually owed', function () {
    $ctx = repaymentContext(owed: 5000);

    expect(fn () => app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 5001, method: 'cash', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    ))->toThrow(RuntimeException::class);

    // Nothing moved: not the debt, not the cash, not a stray payment row.
    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(5000.0)
        ->and(PosDebtPayment::count())->toBe(0)
        ->and(cashIn($ctx['vendor']->id))->toBe(0.0);
});

it('allows collecting exactly what is owed', function () {
    $ctx = repaymentContext(owed: 5000);

    app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 5000, method: 'cash', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

it('refuses a zero or negative amount', function () {
    $ctx = repaymentContext();

    expect(fn () => app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 0, method: 'cash', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    ))->toThrow(RuntimeException::class);
});

it('refuses an unknown payment method', function () {
    $ctx = repaymentContext();

    expect(fn () => app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 1000, method: 'store_credit', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    ))->toThrow(RuntimeException::class);
});

it('refuses a card repayment when the shop has no bank account, and moves nothing', function () {
    $ctx = repaymentContext();

    // Every vendor gets a bank account provisioned on creation — removed
    // here so this exercises the actual "nowhere to go" guard.
    FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'bank')->delete();

    expect(fn () => app(RecordDebtRepaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 1000, method: 'card', collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    ))->toThrow(RuntimeException::class);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0)
        ->and(PosDebtPayment::count())->toBe(0);
});
