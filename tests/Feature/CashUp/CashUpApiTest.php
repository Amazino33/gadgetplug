<?php

use App\Models\CashUpSession;
use App\Models\PosSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

/** A cashier signed in at the till, with their branch assignment in place. */
function tillContext(): array
{
    $ctx = cashUpContext();
    $ctx['vendor']->users()->syncWithoutDetaching([$ctx['cashier']->id]);
    Sanctum::actingAs($ctx['cashier']);

    return $ctx;
}

function apiSale(array $ctx, array $over = []): PosSale
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

function openViaApi(array $ctx, array $payload = [])
{
    return test()->postJson('/api/pos/cash-up/open', array_merge([
        'vendor_id'     => $ctx['vendor']->id,
        'opening_float' => 20000,
        'terminal_id'   => 'MPT-001',
    ], $payload));
}

// ── Opening ──────────────────────────────────────────────────────────────────

test('a cashier opens the day by counting the float in', function () {
    $ctx = tillContext();

    $response = openViaApi($ctx)->assertCreated();

    expect($response->json('session.status'))->toBe('open')
        ->and((float) $response->json('session.opening_float'))->toBe(20000.0);
});

test('the float is required, because a variance without one is a guess', function () {
    $ctx = tillContext();

    openViaApi($ctx, ['opening_float' => null])->assertStatus(422);
});

test('a negative float is refused', function () {
    $ctx = tillContext();

    openViaApi($ctx, ['opening_float' => -5])->assertStatus(422);
});

test('a retried open returns the same day rather than starting a second', function () {
    $ctx = tillContext();

    $first = openViaApi($ctx)->assertCreated();
    $second = openViaApi($ctx)->assertOk();

    expect($second->json('session.id'))->toBe($first->json('session.id'))
        ->and(CashUpSession::count())->toBe(1);
});

test('a till standing in no branch is told plainly', function () {
    $ctx = tillContext();
    // Unassign the cashier and remove the fallback branch entirely.
    $ctx['cashier']->stores()->detach();
    $ctx['store']->update(['is_default' => false]);

    openViaApi($ctx)->assertStatus(422)->assertJsonPath(
        'message',
        'This till is not assigned to a branch, so it cannot run a cash-up. Ask your manager to assign you to a store.'
    );
});

test('a cashier cannot open a cash-up in another vendor books', function () {
    $ctx = tillContext();
    $stranger = App\Models\Vendor::create([
        'user_id' => User::factory()->create()->id, 'name' => 'Someone Else',
    ]);

    openViaApi($ctx, ['vendor_id' => $stranger->id])->assertForbidden();
});

// ── Blind entry (locked decision 3) ──────────────────────────────────────────

test('the till is told nothing about expected figures before counting', function () {
    $ctx = tillContext();
    apiSale($ctx, ['total' => 80000]);
    openViaApi($ctx);

    $response = test()->getJson('/api/pos/cash-up?vendor_id='.$ctx['vendor']->id)->assertOk();

    // Every one of these would let a cashier count backwards from the answer.
    foreach (['expected_cash', 'expected_terminal', 'cash_variance', 'terminal_variance', 'breakdown'] as $field) {
        expect($response->json('session'))->not->toHaveKey($field);
    }

    // The float is theirs to know — they counted it in themselves.
    expect((float) $response->json('session.opening_float'))->toBe(20000.0);
});

test('the open response leaks nothing either', function () {
    $ctx = tillContext();
    apiSale($ctx, ['total' => 80000]);

    $response = openViaApi($ctx)->assertCreated();

    expect($response->json('session'))->not->toHaveKey('expected_cash')
        ->and($response->json('session'))->not->toHaveKey('cash_variance');
});

// ── Closing ──────────────────────────────────────────────────────────────────

test('closing computes the variance and shows the working', function () {
    $ctx = tillContext();
    apiSale($ctx, ['total' => 80000]);
    $id = openViaApi($ctx)->json('session.id');

    // Float 20,000 + 80,000 sales = 100,000 expected. Drawer has 97,000.
    $response = test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id,
        'counted_cash' => 97000, 'counted_terminal' => 0,
    ])->assertOk();

    expect((float) $response->json('session.expected_cash'))->toBe(100000.0)
        ->and((float) $response->json('session.cash_variance'))->toBe(-3000.0)
        ->and($response->json('session.status'))->toBe('pending_review');

    // The arithmetic comes back with the answer.
    $labels = array_column($response->json('breakdown.cash_lines'), 'label');
    expect($labels)->toContain('Opening float')->toContain('Cash sales');
});

test('both counts are required so neither can be tuned to the other', function () {
    $ctx = tillContext();
    $id = openViaApi($ctx)->json('session.id');

    test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 97000,
    ])->assertStatus(422);
});

test('the terminal leg is reconciled separately', function () {
    $ctx = tillContext();
    apiSale($ctx, ['total' => 50000, 'payment_method' => 'card', 'amount_tendered' => 0]);
    $id = openViaApi($ctx, ['opening_float' => 0])->json('session.id');

    $response = test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id,
        'counted_cash' => 0, 'counted_terminal' => 48000,
    ])->assertOk();

    expect((float) $response->json('session.terminal_variance'))->toBe(-2000.0)
        ->and((float) $response->json('session.cash_variance'))->toBe(0.0);
});

test('a cashier may explain the day in words but not rectify it', function () {
    $ctx = tillContext();
    $id = openViaApi($ctx, ['opening_float' => 0])->json('session.id');

    test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id,
        'counted_cash' => 0, 'counted_terminal' => 0,
        'notes' => 'Gave 3,000 to the driver for transport.',
    ])->assertOk();

    // The note reaches the manager, who decides whether it becomes a
    // rectification. The cashier never writes off their own shortage.
    expect(CashUpSession::find($id)->notes)->toContain('transport');
});

test('a second close is refused and the first answer stands', function () {
    $ctx = tillContext();
    $id = openViaApi($ctx, ['opening_float' => 0])->json('session.id');

    test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 5000, 'counted_terminal' => 0,
    ])->assertOk();

    test()->postJson("/api/pos/cash-up/{$id}/close", [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 99999, 'counted_terminal' => 0,
    ])->assertStatus(409);

    expect((float) CashUpSession::find($id)->counted_cash)->toBe(5000.0);
});

test('a retried close with the same key is accepted quietly', function () {
    $ctx = tillContext();
    $id = openViaApi($ctx, ['opening_float' => 0])->json('session.id');

    $payload = [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 5000,
        'counted_terminal' => 0, 'idempotency_key' => 'close-abc',
    ];

    test()->postJson("/api/pos/cash-up/{$id}/close", $payload)->assertOk();

    // The offline queue replays. It must not be met with an error it will keep
    // retrying, nor post a second time.
    test()->postJson("/api/pos/cash-up/{$id}/close", $payload)->assertOk();

    expect((float) CashUpSession::find($id)->counted_cash)->toBe(5000.0);
});

test('a cashier cannot close somebody else drawer', function () {
    $ctx = tillContext();
    $mate = User::factory()->create();
    $ctx['vendor']->users()->syncWithoutDetaching([$mate->id]);
    $mate->stores()->syncWithoutDetaching([$ctx['store']->id]);

    $theirs = openCashUp($ctx, ['cashier_id' => $mate->id, 'business_date' => now()->toDateString()]);

    test()->postJson("/api/pos/cash-up/{$theirs->id}/close", [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 0, 'counted_terminal' => 0,
    ])->assertNotFound();
});

// ── Days left open ───────────────────────────────────────────────────────────

test('a day left open yesterday is offered back rather than hanging forever', function () {
    $ctx = tillContext();
    openCashUp($ctx, ['business_date' => now()->subDay()->toDateString()]);

    $response = test()->getJson('/api/pos/cash-up?vendor_id='.$ctx['vendor']->id)->assertOk();

    expect($response->json('unclosed'))->toHaveCount(1)
        ->and($response->json('session'))->toBeNull();
});

test('an unclosed earlier day can still be closed', function () {
    $ctx = tillContext();
    $yesterday = openCashUp($ctx, [
        'business_date' => now()->subDay()->toDateString(), 'opening_float' => 0,
    ]);

    test()->postJson("/api/pos/cash-up/{$yesterday->id}/close", [
        'vendor_id' => $ctx['vendor']->id, 'counted_cash' => 1000, 'counted_terminal' => 0,
    ])->assertOk();

    expect($yesterday->refresh()->isPendingReview())->toBeTrue();
});

// ── History ──────────────────────────────────────────────────────────────────

test('history shows what is still unexplained, not only what was frozen', function () {
    $ctx = tillContext();
    $session = closeCashUp(
        openCashUp($ctx, ['business_date' => now()->toDateString()]),
        ['cash_variance' => -5000, 'terminal_variance' => 0],
    );

    // The manager later accounts for 3,000 of it.
    App\Models\CashUpRectification::create([
        'cash_up_session_id' => $session->id, 'vendor_id' => $ctx['vendor']->id,
        'kind' => 'expense', 'amount' => 3000, 'created_by' => $ctx['owner']->id,
    ]);

    $response = test()->getJson('/api/pos/cash-up/history?vendor_id='.$ctx['vendor']->id)->assertOk();

    expect((float) $response->json('sessions.0.cash_variance'))->toBe(-5000.0)
        ->and((float) $response->json('sessions.0.resolved_cash_variance'))->toBe(-2000.0);
});

test('the endpoints refuse a caller with no token', function () {
    $ctx = cashUpContext();

    test()->postJson('/api/pos/cash-up/open', [
        'vendor_id' => $ctx['vendor']->id, 'opening_float' => 1000,
    ])->assertUnauthorized();
});
