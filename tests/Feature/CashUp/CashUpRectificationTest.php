<?php

use App\Models\CashUpRectification;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Cash/Helpers.php';

uses(RefreshDatabase::class);

/**
 * A rectification on this cashier's session for the day.
 *
 * Reuses the day's session rather than opening a new one per call — a second
 * open is exactly what the unique key exists to refuse.
 */
function rectify(array $ctx, array $attrs): CashUpRectification
{
    $session = $attrs['session']
        ?? App\Models\CashUpSession::forDay($ctx['cashier']->id, $ctx['store']->id, '2026-09-11')
        ?? openCashUp($ctx);

    unset($attrs['session']);

    return CashUpRectification::create(array_merge([
        'cash_up_session_id' => $session->id,
        'vendor_id'          => $ctx['vendor']->id,
        'created_by'         => $ctx['cashier']->id,
    ], $attrs));
}

// ── Validation ───────────────────────────────────────────────────────────────

test('an unknown kind is refused', function () {
    $ctx = cashUpContext();

    expect(fn () => rectify($ctx, ['kind' => 'shrinkage', 'amount' => 1000]))
        ->toThrow(LogicException::class, 'kind must be one of');
});

test('a negative amount is refused because direction lives in the tenders', function () {
    $ctx = cashUpContext();

    expect(fn () => rectify($ctx, ['kind' => 'expense', 'amount' => -1000]))
        ->toThrow(LogicException::class, 'must be positive');

    expect(fn () => rectify($ctx, ['kind' => 'expense', 'amount' => 0]))
        ->toThrow(LogicException::class, 'must be positive');
});

test('an unknown tender is refused', function () {
    $ctx = cashUpContext();

    expect(fn () => rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 1000,
        'from_tender' => 'cash', 'to_tender' => 'crypto',
    ]))->toThrow(LogicException::class, 'to_tender must be one of');
});

test('a reclass needs both tenders and they must differ', function () {
    $ctx = cashUpContext();

    expect(fn () => rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 1000, 'from_tender' => 'cash',
    ]))->toThrow(LogicException::class, 'needs both');

    expect(fn () => rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 1000,
        'from_tender' => 'cash', 'to_tender' => 'cash',
        'related_sale_id' => null,
    ]))->toThrow(LogicException::class);
});

test('a sale-correcting entry must name the sale it flags', function () {
    $ctx = cashUpContext();

    expect(fn () => rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 1000,
        'from_tender' => 'cash', 'to_tender' => 'card',
    ]))->toThrow(LogicException::class, 'must name the sale it corrects');
});

test('a debt_paid needs the real tender the money arrived on', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['payment_method' => 'debt']);

    expect(fn () => rectify($ctx, [
        'kind' => 'debt_paid', 'amount' => 1000, 'related_sale_id' => $sale->id,
    ]))->toThrow(LogicException::class, 'real tender');
});

// ── Normalisation ────────────────────────────────────────────────────────────

test('money out of the drawer always leaves cash and arrives nowhere', function () {
    $ctx = cashUpContext();

    foreach (['expense', 'cash_out'] as $kind) {
        $entry = rectify($ctx, [
            'kind' => $kind, 'amount' => 2500,
            // Deliberately wrong on the way in; the model settles it.
            'from_tender' => 'card', 'to_tender' => 'card',
        ]);

        expect($entry->from_tender)->toBe('cash')
            ->and($entry->to_tender)->toBeNull();
    }
});

test('a debt_paid always comes off the debt ledger', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['payment_method' => 'debt']);

    $entry = rectify($ctx, [
        'kind' => 'debt_paid', 'amount' => 8000,
        'to_tender' => 'card', 'related_sale_id' => $sale->id,
    ]);

    expect($entry->from_tender)->toBe('debt');
});

// ── Effects on each leg ──────────────────────────────────────────────────────

test('an expense lowers expected cash and leaves the terminal alone', function () {
    $ctx = cashUpContext();
    $entry = rectify($ctx, ['kind' => 'expense', 'amount' => 3000, 'note' => 'Transport']);

    expect($entry->cashEffect())->toBe(-3000.0)
        ->and($entry->terminalEffect())->toBe(0.0);
});

test('a handover lowers expected cash', function () {
    $ctx = cashUpContext();
    $entry = rectify($ctx, ['kind' => 'cash_out', 'amount' => 50000, 'note' => 'Given to Oga']);

    expect($entry->cashEffect())->toBe(-50000.0)
        ->and($entry->terminalEffect())->toBe(0.0);
});

test('a cash-to-card reclass moves the money between the legs', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id);

    $entry = rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 15000,
        'from_tender' => 'cash', 'to_tender' => 'card',
        'related_sale_id' => $sale->id,
    ]);

    expect($entry->cashEffect())->toBe(-15000.0)
        ->and($entry->terminalEffect())->toBe(15000.0);
});

test('card to transfer nets to nothing because both read off the same terminal', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['payment_method' => 'card']);

    $entry = rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 15000,
        'from_tender' => 'card', 'to_tender' => 'bank_transfer',
        'related_sale_id' => $sale->id,
    ]);

    expect($entry->cashEffect())->toBe(0.0)
        ->and($entry->terminalEffect())->toBe(0.0);
});

test('a debt_paid raises one leg without lowering the other', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['payment_method' => 'debt']);

    // Debt was never in either leg, so the money arriving on the terminal is a
    // pure addition — which is exactly why a debt day looks short without this.
    $entry = rectify($ctx, [
        'kind' => 'debt_paid', 'amount' => 20000,
        'to_tender' => 'bank_transfer', 'related_sale_id' => $sale->id,
    ]);

    expect($entry->terminalEffect())->toBe(20000.0)
        ->and($entry->cashEffect())->toBe(0.0);
});

// ── Append-only, and the derived sale flag ───────────────────────────────────

test('a rectification can never be edited or deleted', function () {
    $ctx = cashUpContext();
    $entry = rectify($ctx, ['kind' => 'expense', 'amount' => 1000]);

    expect(fn () => $entry->update(['amount' => 2000]))
        ->toThrow(LogicException::class, 'append-only');

    expect(fn () => $entry->delete())
        ->toThrow(LogicException::class, 'append-only');
});

test('a flagged sale is found by what points at it, not by a column on the sale', function () {
    $ctx = cashUpContext();
    $sale = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id);
    $untouched = cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id);

    $entry = rectify($ctx, [
        'kind' => 'tender_reclass', 'amount' => 5000,
        'from_tender' => 'cash', 'to_tender' => 'card',
        'related_sale_id' => $sale->id,
    ]);

    expect($entry->flagsSale())->toBeTrue()
        ->and(CashUpRectification::flaggingSale($sale->id)->count())->toBe(1)
        ->and(CashUpRectification::flaggingSale($untouched->id)->count())->toBe(0);

    // The sale itself is untouched — it still says what it was rung as.
    expect($sale->refresh()->payment_method)->toBe('cash');
});

test('an expense flags no sale', function () {
    $ctx = cashUpContext();

    expect(rectify($ctx, ['kind' => 'expense', 'amount' => 1000])->flagsSale())->toBeFalse();
});

test('an idempotency key stops a retried push posting twice', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx);

    rectify($ctx, ['session' => $session, 'kind' => 'expense', 'amount' => 1000, 'idempotency_key' => 'rect-abc']);

    expect(fn () => rectify($ctx, [
        'session' => $session, 'kind' => 'expense', 'amount' => 1000, 'idempotency_key' => 'rect-abc',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

test('two genuine identical expenses are both kept', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx);

    // Two real 500-naira transport payments on one day are both real, which is
    // why uniqueness lives on a caller-supplied key rather than on the values.
    rectify($ctx, ['session' => $session, 'kind' => 'expense', 'amount' => 500]);
    rectify($ctx, ['session' => $session, 'kind' => 'expense', 'amount' => 500]);

    expect($session->rectifications()->count())->toBe(2);
});
