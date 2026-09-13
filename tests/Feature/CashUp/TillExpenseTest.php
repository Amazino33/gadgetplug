<?php

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use App\Services\Cash\CashUpExpectation;
use App\Support\Pos\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/** A till signed in, with somewhere for the money to go. */
function expenseContext(): array
{
    $ctx = cashUpContext();
    $ctx['vendor']->users()->syncWithoutDetaching([$ctx['cashier']->id]);

    // Every vendor is given a bank and a cash account the moment it is created
    // (VendorObserver). Creating another here would leave the action posting to
    // the seeded one while the test watched the wrong drawer.
    $ctx['account'] = FinancialAccount::where('vendor_id', $ctx['vendor']->id)
        ->where('type', 'cash')
        ->firstOrFail();

    Sanctum::actingAs($ctx['cashier']);

    return $ctx;
}

function payOut(array $ctx, array $over = [])
{
    return test()->postJson('/api/pos/expenses', array_merge([
        'vendor_id'   => $ctx['vendor']->id,
        'amount'      => 3000,
        'category'    => 'logistics_other',
        'description' => 'Transport for the driver',
    ], $over));
}

function tillSale(array $ctx, array $over = []): PosSale
{
    $total = $over['total'] ?? 80000;

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

// ── Recording it at the counter ──────────────────────────────────────────────

test('a cashier records money leaving the drawer without going to the dashboard', function () {
    $ctx = expenseContext();

    payOut($ctx)->assertCreated();

    $expense = Expense::first();

    expect((float) $expense->amount)->toBe(3000.0)
        ->and($expense->description)->toBe('Transport for the driver')
        ->and($expense->created_by)->toBe($ctx['cashier']->id)
        // The branch it left, so the right drawer is the one that goes down.
        ->and($expense->store_id)->toBe($ctx['store']->id)
        // Dated on the shop's trading day, so the cash-up looks for it there.
        ->and($expense->incurred_at->toDateString())->toBe(BusinessDate::today());
});

test('it is the same expense the dashboard lists, not a till-only record', function () {
    $ctx = expenseContext();
    payOut($ctx)->assertCreated();

    // One table, one list. An owner does not have a second place to remember
    // to look.
    expect(Expense::where('vendor_id', $ctx['vendor']->id)->count())->toBe(1);
});

test('the money actually leaves the accounts', function () {
    $ctx = expenseContext();
    payOut($ctx)->assertCreated();

    $entry = FinancialLedgerEntry::where('financial_account_id', $ctx['account']->id)->first();

    // An expense that never reaches the ledger is money the books believe the
    // shop still has.
    expect($entry)->not->toBeNull()
        ->and($entry->direction)->toBe('out')
        ->and((float) $entry->amount)->toBe(3000.0)
        ->and(Expense::first()->isPosted())->toBeTrue();
});

test('it is refused rather than recorded unposted when there is nowhere to put it', function () {
    $ctx = expenseContext();
    FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->delete();

    payOut($ctx)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no cash account'));

    // The money left the drawer either way. A record that never reached the
    // accounts would be worse than refusing.
    expect(Expense::count())->toBe(0);
});

test('a till cannot book advertising out of the drawer', function () {
    $ctx = expenseContext();

    // Nobody buys Facebook ads from a cash drawer, and offering it only invites
    // a miscategorised row the marketing figures would have to carry.
    payOut($ctx, ['category' => 'advertising'])->assertStatus(422);
});

test('nothing and negatives are refused', function () {
    $ctx = expenseContext();

    payOut($ctx, ['amount' => 0])->assertStatus(422);
    payOut($ctx, ['amount' => -500])->assertStatus(422);
});

test('a cashier sees what they already paid out today', function () {
    $ctx = expenseContext();
    payOut($ctx, ['amount' => 3000]);
    payOut($ctx, ['amount' => 1500, 'description' => 'Airtime']);

    $response = test()->getJson('/api/pos/expenses?vendor_id='.$ctx['vendor']->id)->assertOk();

    // So they can see it is already written down, rather than recording it
    // twice and going short by the difference.
    expect($response->json('expenses'))->toHaveCount(2)
        ->and((float) $response->json('total'))->toBe(4500.0);
});

// ── What it does to the drawer ───────────────────────────────────────────────

test('what was paid out comes off what the drawer should hold', function () {
    $ctx = expenseContext();
    tillSale($ctx, ['total' => 80000]);
    payOut($ctx, ['amount' => 3000]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id,
        storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id,
        businessDate: BusinessDate::today(),
        openingFloat: 20000,
    );

    // 20,000 float + 80,000 sales - 3,000 paid out. Without this the cashier is
    // told they are short by exactly what they just paid out and wrote down.
    expect($breakdown->expectedCash)->toBe(97000.0);

    $line = collect($breakdown->cashLines)->firstWhere('key', 'drawer_payouts');
    expect($line['amount'])->toBe(-3000.0)
        ->and($line['label'])->toBe('Paid out of the drawer');
});

test('the drawer balances for a cashier who paid something out', function () {
    $ctx = expenseContext();
    tillSale($ctx, ['total' => 80000]);
    payOut($ctx, ['amount' => 3000]);

    $session = openCashUp($ctx, ['business_date' => BusinessDate::today(), 'opening_float' => 20000]);

    test()->postJson("/api/pos/sessions/{$session->id}/close", [
        'vendor_id' => $ctx['vendor']->id,
        'counted_cash' => 97000, 'counted_terminal' => 0,
    ])->assertOk();

    // The whole point: they counted what was really there and it balances.
    expect((float) $session->refresh()->cash_variance)->toBe(0.0);
});

test('another cashier payout does not lower this drawer', function () {
    $ctx = expenseContext();
    $mate = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$mate->id]);

    Expense::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category' => 'other', 'amount' => 5000, 'incurred_at' => BusinessDate::today(),
        'created_by' => $mate->id, 'posted_at' => now(),
    ]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id, storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id, businessDate: BusinessDate::today(), openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0);
});

test('an unposted expense has not left the drawer yet', function () {
    $ctx = expenseContext();

    // A note that money will be spent, not a record that it has been.
    Expense::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category' => 'other', 'amount' => 5000, 'incurred_at' => BusinessDate::today(),
        'created_by' => $ctx['cashier']->id, 'posted_at' => null,
    ]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id, storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id, businessDate: BusinessDate::today(), openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0);
});

test('yesterday payout does not follow the cashier into today', function () {
    $ctx = expenseContext();

    Expense::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category' => 'other', 'amount' => 5000,
        'incurred_at' => now()->subDay()->toDateString(),
        'created_by' => $ctx['cashier']->id, 'posted_at' => now(),
    ]);

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id, storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id, businessDate: BusinessDate::today(), openingFloat: 20000,
    );

    expect($breakdown->expectedCash)->toBe(20000.0);
});
