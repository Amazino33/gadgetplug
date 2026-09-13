<?php

use App\Models\PosSession;
use App\Models\User;
use App\Support\Pos\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

// ── Opening ──────────────────────────────────────────────────────────────────

test('a session cannot open without an opening float', function () {
    $ctx = cashUpContext();

    expect(fn () => openCashUp($ctx, ['opening_float' => null]))
        ->toThrow(LogicException::class, 'opening float');
});

test('a cashier gets one session per branch per day', function () {
    $ctx = cashUpContext();
    openCashUp($ctx);

    // The database is the guarantee, not the controller: two retried opens can
    // arrive at once and only the unique key can settle that.
    expect(fn () => openCashUp($ctx))->toThrow(QueryException::class);
});

test('the same cashier may open a session at a different branch on the same day', function () {
    $ctx = cashUpContext();
    openCashUp($ctx);

    $other = App\Models\Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    $second = openCashUp($ctx, ['store_id' => $other->id]);

    expect($second->exists)->toBeTrue();
});

test('an unknown status is refused', function () {
    $ctx = cashUpContext();

    expect(fn () => openCashUp($ctx, ['status' => 'half_done']))
        ->toThrow(LogicException::class, 'status must be one of');
});

test('openFor finds only the day still in progress', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx);

    expect(PosSession::openFor($ctx['cashier']->id, $ctx['store']->id, '2026-09-11')?->id)
        ->toBe($session->id);

    closeCashUp($session);

    expect(PosSession::openFor($ctx['cashier']->id, $ctx['store']->id, '2026-09-11'))->toBeNull();
    expect(PosSession::forDay($ctx['cashier']->id, $ctx['store']->id, '2026-09-11')?->id)->toBe($session->id);
});

// ── Blind entry (locked decision 3) ──────────────────────────────────────────

test('expected figures are withheld until the counts are in', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx);

    $blind = $session->toBlindArray();

    expect($blind)->not->toHaveKey('expected_cash')
        ->and($blind)->not->toHaveKey('expected_terminal')
        ->and($blind)->not->toHaveKey('cash_variance')
        ->and($blind)->not->toHaveKey('terminal_variance')
        ->and($blind)->not->toHaveKey('breakdown')
        ->and($blind)->toHaveKey('opening_float');
});

test('a half-counted session still reveals nothing', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx);

    // Cash counted, terminal not read yet — the terminal figure must stay hidden
    // or the cashier can back into it.
    $session->update(['counted_cash' => 100000]);

    expect($session->countsSubmitted())->toBeFalse()
        ->and($session->toBlindArray())->not->toHaveKey('expected_terminal');
});

test('once both counts are submitted the figures are revealed', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx));

    expect($session->countsSubmitted())->toBeTrue()
        ->and($session->toBlindArray())->toHaveKey('expected_cash')
        ->and($session->toBlindArray())->toHaveKey('cash_variance');
});

// ── Frozen evidence ──────────────────────────────────────────────────────────

test('counts and expected figures freeze once submitted', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx));

    expect(fn () => $session->update(['counted_cash' => 999999]))
        ->toThrow(LogicException::class, 'frozen once counts are submitted');

    // A late offline sale must not retroactively turn a clean cash-up into a
    // shortage nobody was ever shown.
    expect(fn () => $session->update(['expected_cash' => 123456]))
        ->toThrow(LogicException::class, 'frozen once counts are submitted');
});

test('review may still be recorded on a closed session', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx));

    $session->update([
        'status'      => PosSession::STATUS_APPROVED,
        'reviewed_by' => $ctx['owner']->id,
        'reviewed_at' => now(),
        'notes'       => 'Counted with me present.',
    ]);

    expect($session->refresh()->isApproved())->toBeTrue();
});

test('an approved cash-up cannot be reopened', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx));
    $session->update(['status' => PosSession::STATUS_APPROVED, 'reviewed_by' => $ctx['owner']->id]);

    expect(fn () => $session->update(['status' => PosSession::STATUS_OPEN]))
        ->toThrow(LogicException::class, 'cannot be reopened');
});

// ── Cross-checking the two legs ──────────────────────────────────────────────

test('opposite variances of equal size read as a wrong-tender sale', function () {
    $ctx = cashUpContext();

    // Short 5,000 in the drawer, over 5,000 on the terminal: a card sale rung
    // as cash, almost always.
    $session = closeCashUp(openCashUp($ctx), [
        'cash_variance' => -5000, 'terminal_variance' => 5000,
    ]);

    expect($session->legsOffsetEachOther())->toBeTrue()
        ->and($session->netVariance())->toBe(0.0);
});

test('a genuine shortage does not read as a wrong-tender sale', function () {
    $ctx = cashUpContext();
    $session = closeCashUp(openCashUp($ctx), [
        'cash_variance' => -5000, 'terminal_variance' => 0,
    ]);

    expect($session->legsOffsetEachOther())->toBeFalse()
        ->and($session->netVariance())->toBe(-5000.0);
});

test('an open session never reads as offsetting', function () {
    $ctx = cashUpContext();

    expect(openCashUp($ctx)->legsOffsetEachOther())->toBeFalse();
});

// ── Business date ────────────────────────────────────────────────────────────

test('the trading day follows the store clock, not UTC', function () {
    // 00:30 in Lagos on the 12th is still 23:30 UTC on the 11th. Without the
    // shop's own clock, the first hour of each day lands under yesterday.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 23:30:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-09-12');

    CarbonImmutable::setTestNow();
});

test('a business day converts to an app-timezone window', function () {
    [$from, $to] = BusinessDate::boundsFor('2026-09-11');

    // Lagos is UTC+1, so the day starts an hour early in stored terms.
    expect($from->toDateTimeString())->toBe('2026-09-10 23:00:00')
        ->and($to->toDateTimeString())->toBe('2026-09-11 22:59:59');
});
