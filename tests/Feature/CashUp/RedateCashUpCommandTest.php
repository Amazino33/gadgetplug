<?php

use App\Models\CashUpRectification;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 20:00:00');

    $this->ctx = cashUpContext();

    // Exactly what the bug left behind: the 27th's takings, counted and
    // frozen against a 28th that had no sales yet.
    PosSale::create([
        'reference' => 'POS-'.Str::random(10), 'vendor_id' => $this->ctx['vendor']->id,
        'store_id' => $this->ctx['store']->id, 'cashier_id' => $this->ctx['cashier']->id,
        'subtotal' => 90530, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => 90530,
        'payment_method' => 'cash', 'amount_tendered' => 90530, 'change_given' => 0,
        'status' => 'completed', 'completed_at' => '2026-09-27 13:00:00',
    ]);

    $this->session = closeCashUp(
        openCashUp($this->ctx, ['business_date' => '2026-09-28', 'opening_float' => 0]),
        [
            'counted_cash' => 90530, 'counted_terminal' => 0,
            'expected_cash' => 0, 'expected_terminal' => 0,
            'cash_variance' => 90530, 'terminal_variance' => 0,
            'breakdown' => ['cash_lines' => []],
        ],
    );
});

afterEach(fn () => Carbon::setTestNow());

test('a dry run reports the move and changes nothing', function () {
    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27'])
        ->assertSuccessful();

    expect($this->session->refresh()->business_date->toDateString())->toBe('2026-09-28')
        ->and((float) $this->session->cash_variance)->toBe(90530.0);
});

test('it moves the record to its own day and measures it against that day', function () {
    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27', '--force' => true])
        ->assertSuccessful();

    $session = $this->session->refresh();

    expect($session->business_date->toDateString())->toBe('2026-09-27')
        // The cashier's word is untouched.
        ->and((float) $session->counted_cash)->toBe(90530.0)
        ->and((float) $session->expected_cash)->toBe(90530.0)
        ->and((float) $session->cash_variance)->toBe(0.0)
        ->and($session->breakdown['context']['sales_count'])->toBe(1)
        ->and((string) PosZReport::where('pos_session_id', $session->id)->value('report_date'))->toStartWith('2026-09-27');
});

test('the real day is free to be cashed up once it has moved', function () {
    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27', '--force' => true]);

    expect(PosSession::forDay($this->ctx['cashier']->id, $this->ctx['store']->id, '2026-09-28'))->toBeNull();
});

test('it will not move a day onto one the cashier already has', function () {
    openCashUp($this->ctx, ['business_date' => '2026-09-27']);

    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27', '--force' => true])
        ->assertFailed();

    expect($this->session->refresh()->business_date->toDateString())->toBe('2026-09-28');
});

test('it will not move an approved day', function () {
    $this->session->update(['status' => PosSession::STATUS_APPROVED, 'reviewed_by' => $this->ctx['owner']->id]);

    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27', '--force' => true])
        ->assertFailed();
});

test('it will not move a day a manager has already explained', function () {
    CashUpRectification::create([
        'pos_session_id' => $this->session->id, 'vendor_id' => $this->ctx['vendor']->id,
        'kind' => 'expense', 'amount' => 500, 'created_by' => $this->ctx['owner']->id,
    ]);

    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-27', '--force' => true])
        ->assertFailed();
});

test('it will not move a count onto a day the cashier did not trade', function () {
    // 26/09 has no sales for this cashier. A move there would be a guess.
    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-26', '--force' => true])
        ->assertFailed();

    expect($this->session->refresh()->business_date->toDateString())->toBe('2026-09-28');
});

test('it will not move a day into the future', function () {
    $this->artisan('pos:redate-cash-up', ['session' => $this->session->id, 'date' => '2026-09-29', '--force' => true])
        ->assertFailed();
});
