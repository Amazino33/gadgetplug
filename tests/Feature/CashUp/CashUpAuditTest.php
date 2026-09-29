<?php

use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 20:00:00');
    $this->ctx = cashUpContext();
});

afterEach(fn () => Carbon::setTestNow());

function auditSale(array $ctx, string $at, float $total, array $over = []): PosSale
{
    return PosSale::create(array_merge([
        'reference' => 'POS-'.Str::random(10), 'vendor_id' => $ctx['vendor']->id,
        'store_id' => $ctx['store']->id, 'cashier_id' => $ctx['cashier']->id,
        'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => $total,
        'payment_method' => 'cash', 'amount_tendered' => $total, 'change_given' => 0,
        'status' => 'completed', 'completed_at' => $at,
    ], $over));
}

/** Record #136 as it was found: Saturday's count, filed under Monday. */
function misfiledMonday(array $ctx): PosSession
{
    auditSale($ctx, '2026-09-26 15:00:00', 30530);
    auditSale($ctx, '2026-09-26 16:00:00', 81900, ['payment_method' => 'bank_transfer', 'amount_tendered' => 0]);

    return closeCashUp(
        openCashUp($ctx, ['business_date' => '2026-09-28', 'opening_float' => 0]),
        [
            'counted_cash' => 30530, 'counted_terminal' => 81900,
            'expected_cash' => 0, 'expected_terminal' => 0,
            'cash_variance' => 30530, 'terminal_variance' => 81900,
            'breakdown' => ['context' => ['sales_count' => 0]],
        ],
    );
}

// ── The audit ────────────────────────────────────────────────────────────────

test('it names the day a misfiled count balances on, and changes nothing', function () {
    $session = misfiledMonday($this->ctx);

    $this->artisan('pos:audit-cash-ups')
        ->expectsOutputToContain('MISFILED?')
        ->expectsOutputToContain("pos:redate-cash-up {$session->id} 2026-09-26")
        ->assertSuccessful();

    expect($session->refresh()->business_date->toDateString())->toBe('2026-09-28');
});

test('it does not suggest a day that does not balance', function () {
    $session = misfiledMonday($this->ctx);
    PosSession::whereKey($session->id)->update(['counted_cash' => 99999]);

    $this->artisan('pos:audit-cash-ups')
        ->expectsOutputToContain('Do not move it on a guess')
        ->doesntExpectOutputToContain('pos:redate-cash-up')
        ->assertSuccessful();
});

test('it finds a record counted before its sales arrived', function () {
    $session = closeCashUp(
        openCashUp($this->ctx, ['business_date' => '2026-09-25', 'opening_float' => 0]),
        [
            'counted_cash' => 50000, 'counted_terminal' => 0,
            'expected_cash' => 20000, 'expected_terminal' => 0,
            'cash_variance' => 30000, 'terminal_variance' => 0,
            'breakdown' => ['context' => ['sales_count' => 1]],
            'closed_at' => '2026-09-25 17:00:00',
        ],
    );
    auditSale($this->ctx, '2026-09-25 10:00:00', 20000);
    // Rung that day, but reached the server on Monday.
    $late = auditSale($this->ctx, '2026-09-25 16:00:00', 30000);
    PosSale::whereKey($late->id)->update(['created_at' => '2026-09-28 07:36:00']);

    $this->artisan('pos:audit-cash-ups')
        ->expectsOutputToContain('OUT OF DATE')
        ->expectsOutputToContain("pos:refresh-cash-up {$session->id}")
        ->assertSuccessful();
});

test('it lists a day with sales and no record at all', function () {
    auditSale($this->ctx, '2026-09-24 10:00:00', 5000);

    $this->artisan('pos:audit-cash-ups')
        ->expectsOutputToContain('DAYS WITH SALES BUT NO END OF DAY RECORD')
        ->expectsOutputToContain('2026-09-24')
        ->assertSuccessful();
});

test('a clean week is reported as clean', function () {
    auditSale($this->ctx, '2026-09-25 10:00:00', 20000);
    closeCashUp(
        openCashUp($this->ctx, ['business_date' => '2026-09-25', 'opening_float' => 0]),
        ['counted_cash' => 20000, 'counted_terminal' => 0, 'expected_cash' => 20000, 'expected_terminal' => 0,
            'cash_variance' => 0, 'terminal_variance' => 0, 'breakdown' => ['context' => ['sales_count' => 1]]],
    );

    $this->artisan('pos:audit-cash-ups')
        ->expectsOutputToContain('Nothing found')
        ->assertSuccessful();
});

// ── The refresh ──────────────────────────────────────────────────────────────

function staleFriday(array $ctx): PosSession
{
    auditSale($ctx, '2026-09-25 10:00:00', 20000);
    auditSale($ctx, '2026-09-25 16:00:00', 30000);

    return closeCashUp(
        openCashUp($ctx, ['business_date' => '2026-09-25', 'opening_float' => 0]),
        ['counted_cash' => 50000, 'counted_terminal' => 0, 'expected_cash' => 20000, 'expected_terminal' => 0,
            'cash_variance' => 30000, 'terminal_variance' => 0, 'breakdown' => ['context' => ['sales_count' => 1]]],
    );
}

test('a refresh dry run changes nothing', function () {
    $session = staleFriday($this->ctx);

    $this->artisan('pos:refresh-cash-up', ['session' => $session->id])->assertSuccessful();

    expect((float) $session->refresh()->expected_cash)->toBe(20000.0);
});

test('a refresh measures the count against every sale of its own day', function () {
    $session = staleFriday($this->ctx);

    $this->artisan('pos:refresh-cash-up', ['session' => $session->id, '--force' => true])->assertSuccessful();

    $session->refresh();

    expect($session->business_date->toDateString())->toBe('2026-09-25')
        ->and((float) $session->counted_cash)->toBe(50000.0)
        ->and((float) $session->expected_cash)->toBe(50000.0)
        ->and((float) $session->cash_variance)->toBe(0.0);

    // What was there before is kept whole, so it can be put back exactly.
    $log = Spatie\Activitylog\Models\Activity::where('subject_id', $session->id)->latest('id')->first();
    expect($log->properties['before']['expected_cash'])->toBe('20000.00')
        ->and($log->properties['before'])->toHaveKey('breakdown');
});

test('a refresh will not touch an approved day', function () {
    $session = staleFriday($this->ctx);
    $session->update(['status' => PosSession::STATUS_APPROVED, 'reviewed_by' => $this->ctx['owner']->id]);

    $this->artisan('pos:refresh-cash-up', ['session' => $session->id, '--force' => true])->assertFailed();
});
