<?php

use App\Models\CashUpRectification;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Services\Cash\CashUpExpectation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

const CASH_UP_DAY = '2026-09-11';

/** A completed sale on the trading day under test. */
function cashUpSale(array $ctx, array $over = []): PosSale
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
        // Mid-morning in Lagos, comfortably inside the trading day either way.
        'completed_at'    => '2026-09-11 09:00:00',
    ], $over));
}

function cashUpRefund(array $ctx, PosSale $original, float $amount, string $method): PosReturn
{
    return PosReturn::create([
        'reference'        => 'RET-'.Str::random(8),
        'vendor_id'        => $ctx['vendor']->id,
        'original_sale_id' => $original->id,
        'cashier_id'       => $ctx['cashier']->id,
        'return_items'     => [],
        'refund_amount'    => $amount,
        'refund_method'    => $method,
        'created_at'       => '2026-09-11 14:00:00',
    ]);
}

function cashUpExpectation(array $ctx, float $float = 20000, iterable $rectifications = []): App\Support\Pos\CashUpBreakdown
{
    return app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id,
        storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id,
        businessDate: CASH_UP_DAY,
        openingFloat: $float,
        rectifications: $rectifications,
    );
}

/** Pull one line's amount out of a leg by key. */
function cashUpLine(array $lines, string $key): ?float
{
    foreach ($lines as $line) {
        if ($line['key'] === $key) {
            return $line['amount'];
        }
    }

    return null;
}

// ── The cash leg, and its arithmetic ─────────────────────────────────────────

test('expected cash is the float plus the day takings', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000]);

    $b = cashUpExpectation($ctx, float: 20000);

    expect($b->expectedCash)->toBe(100000.0);
});

test('the working is shown, not just the answer', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 80000]);

    $b = cashUpExpectation($ctx, float: 20000);

    // The float is a line of its own, because "why is the drawer more than my
    // sales?" is the first question a cashier asks.
    expect(cashUpLine($b->cashLines, 'opening_float'))->toBe(20000.0)
        ->and(cashUpLine($b->cashLines, 'cash_sales'))->toBe(80000.0);

    // And the lines must actually add up to the figure the cashier is held to.
    expect(round(array_sum(array_column($b->cashLines, 'amount')), 2))->toBe($b->expectedCash);
});

test('change given is not counted as money in the drawer', function () {
    $ctx = cashUpContext();
    // Sold 9,500; customer paid 10,000 and took 500 back.
    cashUpSale($ctx, ['total' => 9500, 'amount_tendered' => 10000, 'change_given' => 500]);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(9500.0);
});

test('a voided sale leaves no money behind', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000, 'status' => 'voided']);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(30000.0);
});

test('cash refunds come off the cash leg', function () {
    $ctx = cashUpContext();
    $s = cashUpSale($ctx, ['total' => 50000]);
    cashUpRefund($ctx, $s, 12000, 'cash');

    $b = cashUpExpectation($ctx, float: 0);

    expect($b->expectedCash)->toBe(38000.0)
        ->and(cashUpLine($b->cashLines, 'cash_refunds'))->toBe(-12000.0);
});

// ── The terminal leg ─────────────────────────────────────────────────────────

test('card and transfer both land on the terminal and are shown apart', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 40000, 'payment_method' => 'card', 'amount_tendered' => 0]);
    cashUpSale($ctx, ['total' => 25000, 'payment_method' => 'bank_transfer', 'amount_tendered' => 0]);

    $b = cashUpExpectation($ctx, float: 0);

    expect($b->expectedTerminal)->toBe(65000.0)
        ->and(cashUpLine($b->terminalLines, 'card_sales'))->toBe(40000.0)
        ->and(cashUpLine($b->terminalLines, 'transfer_sales'))->toBe(25000.0)
        // Terminal money never touches the drawer.
        ->and($b->expectedCash)->toBe(0.0);
});

test('terminal refunds come off the terminal leg', function () {
    $ctx = cashUpContext();
    $s = cashUpSale($ctx, ['total' => 40000, 'payment_method' => 'card', 'amount_tendered' => 0]);
    cashUpRefund($ctx, $s, 15000, 'card');

    $b = cashUpExpectation($ctx, float: 0);

    expect($b->expectedTerminal)->toBe(25000.0)
        ->and($b->expectedCash)->toBe(0.0);
});

// ── Mixed payments ───────────────────────────────────────────────────────────

test('a mixed sale is split across both legs', function () {
    $ctx = cashUpContext();
    $s = cashUpSale($ctx, [
        'total' => 100000, 'payment_method' => 'split',
        'amount_tendered' => 100000, 'change_given' => 0,
    ]);

    PosSalePayment::create(['pos_sale_id' => $s->id, 'method' => 'cash', 'amount' => 30000]);
    PosSalePayment::create(['pos_sale_id' => $s->id, 'method' => 'card', 'amount' => 70000]);

    $b = cashUpExpectation($ctx, float: 0);

    expect($b->expectedCash)->toBe(30000.0)
        ->and($b->expectedTerminal)->toBe(70000.0);
});

test('change on a mixed sale is taken out of the drawer once', function () {
    $ctx = cashUpContext();
    $s = cashUpSale($ctx, [
        'total' => 95000, 'payment_method' => 'split',
        'amount_tendered' => 100000, 'change_given' => 5000,
    ]);

    PosSalePayment::create(['pos_sale_id' => $s->id, 'method' => 'cash', 'amount' => 35000]);
    PosSalePayment::create(['pos_sale_id' => $s->id, 'method' => 'card', 'amount' => 60000]);

    // 35,000 cash in, 5,000 change back out.
    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(30000.0);
});

// ── Debt: money that is in nobody's hands ────────────────────────────────────

test('a credit sale puts nothing in either leg but is reported', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 60000]);
    $debt = cashUpSale($ctx, ['total' => 40000, 'payment_method' => 'debt', 'amount_tendered' => 0]);
    PosSalePayment::create(['pos_sale_id' => $debt->id, 'method' => 'debt', 'amount' => 40000]);

    $b = cashUpExpectation($ctx, float: 0);

    // The usual complaint: "I sold 100,000 and the drawer has 60,000."
    expect($b->expectedCash)->toBe(60000.0)
        ->and($b->expectedTerminal)->toBe(0.0)
        ->and($b->context['debt_rung'])->toBe(40000.0)
        ->and($b->context['gross_sales'])->toBe(100000.0);
});

// ── Attribution ──────────────────────────────────────────────────────────────

test('another cashier takings are not this cashier problem', function () {
    $ctx = cashUpContext();
    $other = App\Models\User::factory()->create();

    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000, 'cashier_id' => $other->id]);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(30000.0);
});

test('another branch takings are not this drawer', function () {
    $ctx = cashUpContext();
    $other = App\Models\Store::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Second Branch', 'is_default' => false,
    ]);

    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000, 'store_id' => $other->id]);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(30000.0);
});

test('yesterday takings are not today problem', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000, 'completed_at' => '2026-09-10 09:00:00']);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(30000.0);
});

test('the trading day runs on the store clock', function () {
    $ctx = cashUpContext();

    // 23:30 UTC on the 11th is 00:30 Lagos on the 12th — tomorrow's takings.
    cashUpSale($ctx, ['total' => 30000, 'completed_at' => '2026-09-11 23:30:00']);
    // 23:30 UTC on the 10th is 00:30 Lagos on the 11th — today's first sale.
    cashUpSale($ctx, ['total' => 7000, 'completed_at' => '2026-09-10 23:30:00']);

    expect(cashUpExpectation($ctx, float: 0)->expectedCash)->toBe(7000.0);
});

// ── Rectifications ───────────────────────────────────────────────────────────

test('money spent out of the drawer lowers what should be in it', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx, ['business_date' => CASH_UP_DAY]);
    cashUpSale($ctx, ['total' => 80000]);

    CashUpRectification::create([
        'pos_session_id' => $session->id, 'vendor_id' => $ctx['vendor']->id,
        'kind' => 'expense', 'amount' => 3000, 'note' => 'Transport',
    ]);
    CashUpRectification::create([
        'pos_session_id' => $session->id, 'vendor_id' => $ctx['vendor']->id,
        'kind' => 'cash_out', 'amount' => 50000, 'note' => 'Given to Oga',
    ]);

    $b = cashUpExpectation($ctx, float: 20000, rectifications: $session->rectifications()->get());

    expect($b->expectedCash)->toBe(47000.0)
        ->and(cashUpLine($b->cashLines, 'expense'))->toBe(-3000.0)
        ->and(cashUpLine($b->cashLines, 'cash_out'))->toBe(-50000.0);
});

test('a wrong-tender correction moves money between the legs', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx, ['business_date' => CASH_UP_DAY]);

    // Rung as cash, actually paid on the terminal.
    $s = cashUpSale($ctx, ['total' => 15000]);

    CashUpRectification::create([
        'pos_session_id' => $session->id, 'vendor_id' => $ctx['vendor']->id,
        'kind' => 'tender_reclass', 'amount' => 15000,
        'from_tender' => 'cash', 'to_tender' => 'card', 'related_sale_id' => $s->id,
    ]);

    $b = cashUpExpectation($ctx, float: 0, rectifications: $session->rectifications()->get());

    expect($b->expectedCash)->toBe(0.0)
        ->and($b->expectedTerminal)->toBe(15000.0);

    // And the sale itself still says what it was rung as.
    expect($s->refresh()->payment_method)->toBe('cash');
});

test('a credit sale that was actually paid raises the leg it was paid on', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx, ['business_date' => CASH_UP_DAY]);

    $debt = cashUpSale($ctx, ['total' => 20000, 'payment_method' => 'debt', 'amount_tendered' => 0]);
    PosSalePayment::create(['pos_sale_id' => $debt->id, 'method' => 'debt', 'amount' => 20000]);

    CashUpRectification::create([
        'pos_session_id' => $session->id, 'vendor_id' => $ctx['vendor']->id,
        'kind' => 'debt_paid', 'amount' => 20000,
        'to_tender' => 'bank_transfer', 'related_sale_id' => $debt->id,
    ]);

    $b = cashUpExpectation($ctx, float: 0, rectifications: $session->rectifications()->get());

    // Debt was in neither leg, so this is a pure addition to the terminal.
    expect($b->expectedTerminal)->toBe(20000.0)
        ->and($b->expectedCash)->toBe(0.0);
});

// ── Variance ─────────────────────────────────────────────────────────────────

test('variance is counted minus expected on each leg', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 80000]);

    $b = cashUpExpectation($ctx, float: 20000);

    expect($b->cashVarianceAgainst(95000))->toBe(-5000.0)
        ->and($b->cashVarianceAgainst(100000))->toBe(0.0)
        ->and($b->cashVarianceAgainst(103000))->toBe(3000.0);
});

// ── Warnings ─────────────────────────────────────────────────────────────────

test('sales belonging to no branch are flagged rather than silently dropped', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 30000]);
    cashUpSale($ctx, ['total' => 50000, 'store_id' => null]);

    $b = cashUpExpectation($ctx, float: 0);

    // A shortage caused by a data gap must never be put to a cashier as if it
    // were missing money.
    expect($b->expectedCash)->toBe(30000.0)
        ->and($b->context['sales_without_store'])->toBe(1)
        ->and($b->warnings())->toHaveCount(1)
        ->and($b->warnings()[0])->toContain('not assigned to any branch');
});

test('a clean day raises no warnings', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 30000]);

    expect(cashUpExpectation($ctx, float: 0)->warnings())->toBeEmpty();
});

// ── Reuse ────────────────────────────────────────────────────────────────────

test('a session computes its own figures from its own float', function () {
    $ctx = cashUpContext();
    $session = openCashUp($ctx, ['business_date' => CASH_UP_DAY, 'opening_float' => 15000]);
    cashUpSale($ctx, ['total' => 40000]);

    $b = app(CashUpExpectation::class)->for($session);

    expect($b->expectedCash)->toBe(55000.0);
});

test('the snapshot keeps the working, not just the totals', function () {
    $ctx = cashUpContext();
    cashUpSale($ctx, ['total' => 80000]);

    $snapshot = cashUpExpectation($ctx, float: 20000)->toArray();

    expect($snapshot)->toHaveKeys([
        'expected_cash', 'expected_terminal', 'cash_lines', 'terminal_lines', 'context', 'warnings',
    ])->and($snapshot['cash_lines'][0]['label'])->toBe('Opening float');
});
