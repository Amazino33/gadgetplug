<?php

use App\Actions\CashUp\ApproveCashUpAction;
use App\Actions\CashUp\RecordRectificationAction;
use App\Models\AccountabilityLedgerEntry;
use App\Models\PosSession;
use App\Models\FinancialAccount;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\User;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

function postingSale(array $ctx, array $over = []): PosSale
{
    $total = $over['total'] ?? 10000;

    return PosSale::create(array_merge([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $ctx['vendor']->id,
        'store_id'        => $ctx['store']->id,
        'cashier_id'      => $ctx['cashier']->id,
        'subtotal'        => $total,
        'discount_amount' => 0,
        'vat_amount'      => 0,
        'total'           => $total,
        'payment_method'  => 'cash',
        'amount_tendered' => $total,
        'change_given'    => 0,
        'status'          => 'completed',
        'completed_at'    => now(),
    ], $over));
}

/** A submitted cash-up with a difference on it, awaiting review. */
function pendingCashUp(array $ctx, array $over = []): PosSession
{
    return closeCashUp(
        openCashUp($ctx, ['business_date' => now()->toDateString()]),
        array_merge([
            'expected_cash' => 100000, 'counted_cash' => 95000, 'cash_variance' => -5000,
            'expected_terminal' => 0, 'counted_terminal' => 0, 'terminal_variance' => 0,
        ], $over),
    );
}

function rectifyAs(PosSession $session, User $manager, array $args): App\Models\CashUpRectification
{
    return app(RecordRectificationAction::class)->execute(
        session: $session,
        manager: $manager,
        kind: $args['kind'],
        amount: $args['amount'],
        fromTender: $args['from_tender'] ?? null,
        toTender: $args['to_tender'] ?? null,
        relatedSaleId: $args['related_sale_id'] ?? null,
        note: $args['note'] ?? null,
        idempotencyKey: $args['idempotency_key'] ?? null,
    );
}

function approveAs(PosSession $session, User $reviewer): PosSession
{
    return app(ApproveCashUpAction::class)->execute($session, $reviewer);
}

// ── Who may rectify ──────────────────────────────────────────────────────────

test('a cashier cannot explain away a difference on their own drawer', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    expect(fn () => rectifyAs($session, $ctx['cashier'], ['kind' => 'expense', 'amount' => 5000]))
        ->toThrow(RuntimeException::class, 'your own drawer');
});

test('a cash-up cannot be rectified before the counts are in', function () {
    $ctx = cashUpContext();
    $open = openCashUp($ctx, ['business_date' => now()->toDateString()]);

    expect(fn () => rectifyAs($open, $ctx['owner'], ['kind' => 'expense', 'amount' => 1000]))
        ->toThrow(RuntimeException::class, 'submitted their counts');
});

test('an approved cash-up can no longer be rectified', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);
    approveAs($session, $ctx['owner']);

    expect(fn () => rectifyAs($session->refresh(), $ctx['owner'], ['kind' => 'expense', 'amount' => 1000]))
        ->toThrow(RuntimeException::class, 'can no longer be rectified');
});

test('a correction cannot be aimed at another cashier sale', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);
    $stranger = postingSale($ctx, ['cashier_id' => User::factory()->create()->id]);

    expect(fn () => rectifyAs($session, $ctx['owner'], [
        'kind' => 'tender_reclass', 'amount' => 1000,
        'from_tender' => 'cash', 'to_tender' => 'card', 'related_sale_id' => $stranger->id,
    ]))->toThrow(RuntimeException::class, 'does not belong to this cash-up');
});

// ── Rectification shrinks what is owed, without touching the record ──────────

test('an expense reduces what is still missing but not what was frozen', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    rectifyAs($session, $ctx['owner'], ['kind' => 'expense', 'amount' => 3000, 'note' => 'Transport']);
    $session->refresh()->load('rectifications');

    expect((float) $session->cash_variance)->toBe(-5000.0)
        ->and($session->resolvedCashVariance())->toBe(-2000.0);
});

test('a wrong-tender correction clears both legs and leaves the sale alone', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx, [
        'expected_cash' => 100000, 'counted_cash' => 85000, 'cash_variance' => -15000,
        'expected_terminal' => 0, 'counted_terminal' => 15000, 'terminal_variance' => 15000,
    ]);
    $sale = postingSale($ctx, ['total' => 15000]);

    rectifyAs($session, $ctx['owner'], [
        'kind' => 'tender_reclass', 'amount' => 15000,
        'from_tender' => 'cash', 'to_tender' => 'card', 'related_sale_id' => $sale->id,
    ]);
    $session->refresh()->load('rectifications');

    expect($session->resolvedCashVariance())->toBe(0.0)
        ->and($session->resolvedTerminalVariance())->toBe(0.0)
        ->and($session->isFullyExplained())->toBeTrue();

    // The sale still says exactly what it was rung as.
    expect($sale->refresh()->payment_method)->toBe('cash')
        ->and($sale->isFlaggedForCorrection())->toBeTrue();
});

test('an unflagged sale stays unflagged', function () {
    $ctx = cashUpContext();
    pendingCashUp($ctx);

    expect(postingSale($ctx)->isFlaggedForCorrection())->toBeFalse();
});

// ── debt_paid moves the customer debt, append-only ───────────────────────────

function debtContext(): array
{
    $ctx = cashUpContext();

    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Cash Drawer', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    $ctx['customer'] = PosCustomer::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '08031234567',
    ]);

    $ctx['debtSale'] = postingSale($ctx, [
        'total' => 20000, 'payment_method' => 'debt', 'amount_tendered' => 0,
        'customer_id' => $ctx['customer']->id,
    ]);

    PosSalePayment::create([
        'pos_sale_id' => $ctx['debtSale']->id, 'method' => 'debt', 'amount' => 20000,
    ]);

    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $ctx['customer']->id, 'vendor_id' => $ctx['vendor']->id,
        'direction' => 'charge', 'amount' => 20000, 'store_id' => $ctx['store']->id,
        'occurred_at' => now()->toDateString(), 'description' => 'Credit sale',
    ]);

    return $ctx;
}

test('a credit sale that was actually paid stops being a debt', function () {
    $ctx = debtContext();
    $session = pendingCashUp($ctx, [
        'expected_terminal' => 0, 'counted_terminal' => 20000, 'terminal_variance' => 20000,
        'expected_cash' => 100000, 'counted_cash' => 100000, 'cash_variance' => 0,
    ]);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(20000.0);

    rectifyAs($session, $ctx['owner'], [
        'kind' => 'debt_paid', 'amount' => 20000,
        'to_tender' => 'bank_transfer', 'related_sale_id' => $ctx['debtSale']->id,
    ]);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);

    // The original charge is untouched; a payment was appended beside it.
    expect(PosCustomerLedgerEntry::where('pos_customer_id', $ctx['customer']->id)->count())->toBe(2)
        ->and(PosCustomerLedgerEntry::where('direction', 'charge')->first()->amount)->toBe('20000.00');

    $session->refresh()->load('rectifications');
    expect($session->resolvedTerminalVariance())->toBe(0.0);
});

test('settling more than is owed is refused', function () {
    $ctx = debtContext();
    $session = pendingCashUp($ctx);

    expect(fn () => rectifyAs($session, $ctx['owner'], [
        'kind' => 'debt_paid', 'amount' => 50000,
        'to_tender' => 'cash', 'related_sale_id' => $ctx['debtSale']->id,
    ]))->toThrow(RuntimeException::class, 'cannot be settled');
});

test('a debt_paid against a sale with no customer is refused', function () {
    $ctx = debtContext();
    $session = pendingCashUp($ctx);
    $anonymous = postingSale($ctx, ['total' => 5000]);

    expect(fn () => rectifyAs($session, $ctx['owner'], [
        'kind' => 'debt_paid', 'amount' => 5000,
        'to_tender' => 'cash', 'related_sale_id' => $anonymous->id,
    ]))->toThrow(RuntimeException::class, 'no customer');
});

test('a failed debt settlement leaves no rectification behind', function () {
    $ctx = debtContext();
    $session = pendingCashUp($ctx);

    try {
        rectifyAs($session, $ctx['owner'], [
            'kind' => 'debt_paid', 'amount' => 50000,
            'to_tender' => 'cash', 'related_sale_id' => $ctx['debtSale']->id,
        ]);
    } catch (RuntimeException) {
        // expected
    }

    // The whole batch rolls back together, or the day would show an explanation
    // for money that was never taken off the debt.
    expect($session->rectifications()->count())->toBe(0)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(20000.0);
});

// ── Approval posts the variance ──────────────────────────────────────────────

test('approval charges only what is still unexplained', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    rectifyAs($session, $ctx['owner'], ['kind' => 'expense', 'amount' => 3000]);
    approveAs($session->refresh(), $ctx['owner']);

    // Frozen gap was 5,000; 3,000 was accounted for; 2,000 is real.
    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(2000.0);

    $row = ApproveCashUpAction::postingFor($session);
    expect($row->entry_type)->toBe('cash_shortage')
        ->and($row->store_id)->toBe($ctx['store']->id)
        ->and($row->source_type)->toBe(PosSession::class)
        ->and((int) $row->source_id)->toBe($session->id);
});

test('a fully explained day charges nobody anything', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    rectifyAs($session, $ctx['owner'], ['kind' => 'expense', 'amount' => 5000]);
    approveAs($session->refresh(), $ctx['owner']);

    // A zero-amount row would say a cashier was accused of an amount.
    expect(ApproveCashUpAction::postingFor($session))->toBeNull()
        ->and(AccountabilityLedgerEntry::count())->toBe(0);
});

test('a clean cash-up posts nothing', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx, ['counted_cash' => 100000, 'cash_variance' => 0]);

    approveAs($session, $ctx['owner']);

    expect(AccountabilityLedgerEntry::count())->toBe(0)
        ->and($session->refresh()->isApproved())->toBeTrue();
});

test('an overage reduces what the cashier owes', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx, ['counted_cash' => 104000, 'cash_variance' => 4000]);

    approveAs($session, $ctx['owner']);

    expect(ApproveCashUpAction::postingFor($session)->entry_type)->toBe('cash_overage')
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(-4000.0);
});

test('nobody approves their own cash-up', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    expect(fn () => approveAs($session, $ctx['cashier']))
        ->toThrow(RuntimeException::class, 'your own cash-up');
});

test('an unsubmitted cash-up cannot be approved', function () {
    $ctx = cashUpContext();
    $open = openCashUp($ctx, ['business_date' => now()->toDateString()]);

    expect(fn () => approveAs($open, $ctx['owner']))
        ->toThrow(RuntimeException::class, 'not been submitted');
});

test('approving twice posts one ledger row', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);

    approveAs($session, $ctx['owner']);
    approveAs($session->refresh(), $ctx['owner']);

    expect(AccountabilityLedgerEntry::cashVariances()->count())->toBe(1)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(5000.0);
});

test('the posted row discloses no product cost', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);
    approveAs($session, $ctx['owner']);

    expect(ApproveCashUpAction::postingFor($session)->disclosesCost())->toBeFalse();
});

test('a cash shortage sits beside a stock shortage in one balance', function () {
    $ctx = cashUpContext();
    $session = pendingCashUp($ctx);
    approveAs($session, $ctx['owner']);

    // The whole reason for reusing this ledger: one answer to "what does this
    // person owe me?", not two.
    AccountabilityLedgerEntry::create([
        'vendor_id' => $ctx['vendor']->id, 'storekeeper_id' => $ctx['cashier']->id,
        'entry_type' => 'charge', 'amount' => 12000, 'shortage_qty' => 1,
        'idempotency_key' => 'charge:case:1', 'created_at' => now(),
    ]);

    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(17000.0);
});
