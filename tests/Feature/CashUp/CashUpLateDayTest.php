<?php

// A shift that reaches the server on a later day than it was opened.
//
// On 28/09/2026 Cashier Itel's 27th arrived on the 28th and was filed as the
// 28th: yesterday's counts frozen against a day with no sales yet ("still
// over" by everything counted), and the real 28th locked out as already
// cashed up.

use App\Http\Controllers\Pos\PosSessionController;
use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Midday in Lagos, well clear of either midnight.
    Carbon::setTestNow('2026-09-28 11:00:00');

    $this->ctx = cashUpContext();
    $this->ctx['vendor']->users()->syncWithoutDetaching([$this->ctx['cashier']->id]);
    Sanctum::actingAs($this->ctx['cashier']);
});

afterEach(fn () => Carbon::setTestNow());

function lateOpen(array $ctx, array $payload = [])
{
    return test()->postJson('/api/pos/sessions/open', array_merge([
        'vendor_id' => $ctx['vendor']->id, 'opening_float' => 0,
    ], $payload));
}

function lateClose(int $id, array $ctx, array $payload = [])
{
    return test()->postJson("/api/pos/sessions/{$id}/close", array_merge([
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 0, 'counted_terminal' => 0,
    ], $payload));
}

function saleOn(array $ctx, string $at, float $total): PosSale
{
    return PosSale::create([
        'reference' => 'POS-'.Str::random(10), 'vendor_id' => $ctx['vendor']->id,
        'store_id' => $ctx['store']->id, 'cashier_id' => $ctx['cashier']->id,
        'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => $total,
        'payment_method' => 'cash', 'amount_tendered' => $total, 'change_given' => 0,
        'status' => 'completed', 'completed_at' => $at,
    ]);
}

test('a shift that arrives a day late is filed under the day it was opened', function () {
    $id = lateOpen($this->ctx, ['business_date' => '2026-09-27'])->assertCreated()->json('session.id');

    expect(PosSession::find($id)->business_date->toDateString())->toBe('2026-09-27');
});

test('its count is measured against its own day, not the day it arrived', function () {
    saleOn($this->ctx, '2026-09-27 14:00:00', 90000);
    saleOn($this->ctx, '2026-09-28 10:00:00', 5000);

    $id = lateOpen($this->ctx, ['business_date' => '2026-09-27'])->json('session.id');

    $response = lateClose($id, $this->ctx, ['counted_cash' => 90000, 'business_date' => '2026-09-27'])->assertOk();

    expect((float) $response->json('session.expected_cash'))->toBe(90000.0)
        ->and((float) $response->json('session.cash_variance'))->toBe(0.0);
});

test('a late yesterday does not lock the cashier out of today', function () {
    $yesterday = lateOpen($this->ctx, ['business_date' => '2026-09-27'])->json('session.id');
    lateClose($yesterday, $this->ctx, ['business_date' => '2026-09-27'])->assertOk();

    $today = lateOpen($this->ctx, ['business_date' => '2026-09-28'])->assertCreated()->json('session.id');

    expect($today)->not->toBe($yesterday)
        ->and(PosSession::find($today)->isOpen())->toBeTrue();
});

test('a till that sends no date still opens today', function () {
    $id = lateOpen($this->ctx)->assertCreated()->json('session.id');

    expect(PosSession::find($id)->business_date->toDateString())->toBe('2026-09-28');
});

test('a day that has not started yet is refused', function () {
    lateOpen($this->ctx, ['business_date' => '2026-09-29'])->assertStatus(422);

    expect(PosSession::count())->toBe(0);
});

test('a count for one day is not accepted against another day session', function () {
    $session = openCashUp($this->ctx, ['business_date' => '2026-09-27', 'opening_float' => 0]);

    lateClose($session->id, $this->ctx, ['counted_cash' => 5000, 'business_date' => '2026-09-28'])
        ->assertStatus(409)
        ->assertJsonPath('code', PosSessionController::WRONG_DAY);

    // Untouched, so the right count can still land on it.
    expect($session->refresh()->isOpen())->toBeTrue()
        ->and($session->counted_cash)->toBeNull();
});

test('a wrong-day close is told apart from an already-closed one', function () {
    $session = closeCashUp(openCashUp($this->ctx, ['business_date' => '2026-09-27']));

    // The till would otherwise adopt this closed day as its own and drop the
    // count it is holding.
    lateClose($session->id, $this->ctx, ['business_date' => '2026-09-28'])
        ->assertStatus(409)
        ->assertJsonPath('code', PosSessionController::WRONG_DAY);
});
