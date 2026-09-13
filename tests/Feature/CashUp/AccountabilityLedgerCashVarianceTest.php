<?php

use App\Models\AccountabilityLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/** A ledger row, with only the fields a cash variance actually carries. */
function ledgerRow(array $ctx, array $attrs): AccountabilityLedgerEntry
{
    return AccountabilityLedgerEntry::create(array_merge([
        'vendor_id'     => $ctx['vendor']->id,
        'store_id'      => $ctx['store']->id,
        'storekeeper_id' => $ctx['cashier']->id,
        'created_by'    => $ctx['owner']->id,
        'created_at'    => now(),
    ], $attrs));
}

// ── The new types, and the sign invariant they had to preserve ───────────────

test('a shortage increases what the cashier owes', function () {
    $ctx = cashUpContext();
    $row = ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => 5000]);

    expect($row->isCashVariance())->toBeTrue()
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(5000.0);
});

test('an overage reduces what the cashier owes', function () {
    $ctx = cashUpContext();
    ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => 5000]);
    ledgerRow($ctx, ['entry_type' => 'cash_overage', 'amount' => -2000]);

    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($ctx['cashier']->id, $ctx['vendor']->id))
        ->toBe(3000.0);
});

test('a shortage may not be negative', function () {
    $ctx = cashUpContext();

    expect(fn () => ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => -5000]))
        ->toThrow(LogicException::class, 'cannot be negative');
});

test('an overage may not be positive', function () {
    $ctx = cashUpContext();

    expect(fn () => ledgerRow($ctx, ['entry_type' => 'cash_overage', 'amount' => 2000]))
        ->toThrow(LogicException::class, 'cannot be positive');
});

test('the stock side of the ledger is unchanged', function () {
    $ctx = cashUpContext();

    // The reason cash variance became two types rather than one signed type: the
    // existing invariant had to survive untouched.
    expect(fn () => ledgerRow($ctx, ['entry_type' => 'charge', 'amount' => -1]))
        ->toThrow(LogicException::class, 'cannot be negative');

    expect(fn () => ledgerRow($ctx, ['entry_type' => 'recovery_cash', 'amount' => 1]))
        ->toThrow(LogicException::class, 'cannot be positive');

    expect(ledgerRow($ctx, ['entry_type' => 'charge', 'amount' => 100, 'shortage_qty' => 1])->isCharge())
        ->toBeTrue();
});

test('an unknown entry type is still refused', function () {
    $ctx = cashUpContext();

    expect(fn () => ledgerRow($ctx, ['entry_type' => 'vibes', 'amount' => 100]))
        ->toThrow(LogicException::class, 'entry_type must be one of');
});

// ── Immutability, unchanged ──────────────────────────────────────────────────

test('a cash variance row is append-only like every other row here', function () {
    $ctx = cashUpContext();
    $row = ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => 5000]);

    expect(fn () => $row->update(['amount' => 1]))->toThrow(LogicException::class, 'append-only');
    expect(fn () => $row->delete())->toThrow(LogicException::class, 'append-only');
});

// ── The new columns ──────────────────────────────────────────────────────────

test('a variance is traceable to the branch and the session that produced it', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx), ['cash_variance' => -5000]);

    $row = ledgerRow($ctx, [
        'entry_type'  => 'cash_shortage',
        'amount'      => 5000,
        'source_type' => App\Models\PosSession::class,
        'source_id'   => $session->id,
    ]);

    expect($row->store->id)->toBe($ctx['store']->id)
        ->and(AccountabilityLedgerEntry::forSource(App\Models\PosSession::class, $session->id)->count())
        ->toBe(1)
        ->and(AccountabilityLedgerEntry::query()->forStore($ctx['store']->id)->cashVariances()->count())
        ->toBe(1);
});

test('a cash variance discloses no product cost', function () {
    $ctx = cashUpContext();
    $row = ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => 5000]);

    // Which is what lets a cash-up review screen show these rows to a manager who
    // may not see stock costs.
    expect($row->disclosesCost())->toBeFalse();
});

test('the idempotency key still guards against double-posting', function () {
    $ctx = cashUpContext();
    ledgerRow($ctx, ['entry_type' => 'cash_shortage', 'amount' => 5000, 'idempotency_key' => 'cashup:41:cash']);

    expect(fn () => ledgerRow($ctx, [
        'entry_type' => 'cash_shortage', 'amount' => 5000, 'idempotency_key' => 'cashup:41:cash',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
