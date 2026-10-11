<?php

use App\Models\PosSale;
use App\Models\PosSession;
use App\Support\Pos\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

// The Z-report a cashier opens from the till. It used to be fetched by the
// session id the till remembered, which could be yesterday's — so it showed
// no sales. The till now asks for today's, and the server works out which.

function zTill(): array
{
    $ctx = cashUpContext();
    $ctx['vendor']->users()->syncWithoutDetaching([$ctx['cashier']->id]);
    Sanctum::actingAs($ctx['cashier']);

    return $ctx;
}

function zSale(array $ctx, array $over = []): PosSale
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

function zOpen(array $ctx): int
{
    return test()->postJson('/api/pos/sessions/open', [
        'vendor_id' => $ctx['vendor']->id, 'opening_float' => 20000, 'terminal_id' => 'MPT-001',
    ])->assertCreated()->json('session.id');
}

function todayZ(array $ctx)
{
    return test()->getJson('/api/pos/sessions/today/z-report?vendor_id='.$ctx['vendor']->id);
}

test('mid-shift, today\'s Z-report shows the sales rung so far, synced ones included', function () {
    $ctx = zTill();
    $sessionId = zOpen($ctx);

    zSale($ctx, ['total' => 5000, 'payment_method' => 'cash', 'pos_session_id' => $sessionId]);
    // Rung offline and synced later: no session id at all.
    zSale($ctx, ['total' => 3000, 'payment_method' => 'card', 'pos_session_id' => null]);

    $report = todayZ($ctx)->assertOk()->json();

    expect((float) $report['total_sales'])->toBe(8000.0)
        ->and((float) $report['cash_sales'])->toBe(5000.0)
        ->and((float) $report['card_sales'])->toBe(3000.0)
        ->and($report['transaction_count'])->toBe(2)
        ->and($report['pos_session_id'])->toBe($sessionId);
});

test('ignores yesterday\'s closed session and reports today', function () {
    $ctx = zTill();

    // Yesterday's day, closed with its own (empty) slip — what a stale till
    // was still asking for.
    $yesterday = PosSession::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'cashier_id' => $ctx['cashier']->id,
        'business_date' => now()->subDay()->toDateString(), 'opening_float' => 0,
        'status' => PosSession::STATUS_PENDING_REVIEW, 'opened_at' => now()->subDay(), 'closed_at' => now()->subDay(),
    ]);

    $todayId = zOpen($ctx);
    zSale($ctx, ['total' => 7000]);

    $report = todayZ($ctx)->assertOk()->json();

    expect($report['pos_session_id'])->toBe($todayId)
        ->and($report['pos_session_id'])->not->toBe($yesterday->id)
        ->and((float) $report['total_sales'])->toBe(7000.0);
});

test('still reports today\'s sales when the day has not reached the server yet', function () {
    $ctx = zTill();

    // No session row — the till opened the day with no signal — but its
    // sales have synced.
    zSale($ctx, ['total' => 4500, 'payment_method' => 'bank_transfer']);

    $report = todayZ($ctx)->assertOk()->json();

    expect((float) $report['total_sales'])->toBe(4500.0)
        ->and((float) $report['bank_transfer_sales'])->toBe(4500.0)
        ->and(substr((string) $report['report_date'], 0, 10))->toBe(BusinessDate::today())
        ->and(PosSession::count())->toBe(0);
});

test('shows only the signed-in cashier\'s own sales', function () {
    $ctx = zTill();
    zOpen($ctx);

    zSale($ctx, ['total' => 2000]);
    zSale($ctx, ['total' => 9999, 'cashier_id' => \App\Models\User::factory()->create()->id]);

    expect((float) todayZ($ctx)->json('total_sales'))->toBe(2000.0);
});
