<?php

use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Goods that come back cannot still be owed for. Without this the debt list
// sends a storekeeper to collect for a product sitting on the shelf.

function reversalContext(): array
{
    $ctx = debtTenderContext();

    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Cash', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
    ]);
    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Bank', 'type' => 'bank',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    return $ctx;
}

function creditSale(array $ctx, array $overrides = []): PosSale
{
    Sanctum::actingAs($ctx['owner']);

    test()->postJson('/api/pos/sales', debtSalePayload($ctx, $overrides))->assertSuccessful();

    return PosSale::latest('id')->firstOrFail();
}

// ─── Void ───────────────────────────────────────────────────────────────

it('cancels the whole debt when a credit sale is voided', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);

    $this->postJson("/api/pos/sales/{$sale->id}/void", ['reason' => 'Rang up in error'])
        ->assertSuccessful();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

it('records the cancellation rather than erasing the charge', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx);

    $this->postJson("/api/pos/sales/{$sale->id}/void", ['reason' => 'Error'])->assertSuccessful();

    $rows = PosCustomerLedgerEntry::orderBy('id')->get();

    // The charge stays. History has to keep saying credit was extended and then
    // reversed, not pretend it never happened.
    expect($rows)->toHaveCount(2)
        ->and($rows->first()->direction)->toBe('charge')
        ->and($rows->last()->direction)->toBe('writeoff')
        ->and($rows->last()->description)->toContain('voided');
});

it('does not cancel twice when a void is retried', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx);

    $this->postJson("/api/pos/sales/{$sale->id}/void", ['reason' => 'Error'])->assertSuccessful();
    $this->postJson("/api/pos/sales/{$sale->id}/void", ['reason' => 'Error']);

    expect(PosCustomerLedgerEntry::where('direction', 'writeoff')->count())->toBe(1)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

it('leaves a fully paid customer alone when their sale is voided', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx);

    app(App\Actions\Pos\RecordCustomerPaymentAction::class)->execute(
        customer: $ctx['customer'], amount: 10000, collectedBy: $ctx['owner'], storeId: $ctx['store']->id,
    );

    $this->postJson("/api/pos/sales/{$sale->id}/void", ['reason' => 'Error'])->assertSuccessful();

    // They already paid. Cancelling the debt again would put them in credit for
    // goods they were never charged for — a refund is a different conversation.
    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0)
        ->and(PosCustomerLedgerEntry::where('direction', 'writeoff')->count())->toBe(0);
});

// ─── Return ─────────────────────────────────────────────────────────────

it('cancels the debt for goods handed back', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx);

    $this->postJson("/api/pos/sales/{$sale->id}/return", [
        'items'         => [['product_id' => $ctx['product']->id, 'quantity' => 1]],
        'refund_method' => 'store_credit',
        'reason'        => 'Faulty',
    ])->assertSuccessful();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

it('cancels only the returned share of a part-paid credit sale', function () {
    $ctx = reversalContext();

    // 4,000 paid at the till, 6,000 on credit.
    $sale = creditSale($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 4000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]);

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);

    $this->postJson("/api/pos/sales/{$sale->id}/return", [
        'items'         => [['product_id' => $ctx['product']->id, 'quantity' => 1]],
        'refund_method' => 'store_credit',
    ])->assertSuccessful();

    // Only the unpaid 60% of the returned value was debt; the other 40% was
    // real money and belongs to the refund, not to the ledger.
    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

it('leaves a cash sale return well alone', function () {
    $ctx  = reversalContext();
    $sale = creditSale($ctx, ['payment_method' => 'cash', 'amount_tendered' => 10000.0]);

    $this->postJson("/api/pos/sales/{$sale->id}/return", [
        'items'         => [['product_id' => $ctx['product']->id, 'quantity' => 1]],
        'refund_method' => 'cash',
    ])->assertSuccessful();

    // Nothing was ever owed, so nothing is cancelled.
    expect(PosCustomerLedgerEntry::count())->toBe(0);
});

// ─── Offline sync now recognises revenue ────────────────────────────────

it('recognises revenue for a cash sale that synced from offline', function () {
    $ctx = reversalContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'      => 'till-cash-1',
            'customer_id'     => null,
            'items'           => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget',
                'unit_price' => 10000.0, 'quantity' => 1, 'total' => 10000.0,
            ]],
            'total'           => 10000.0,
            'vat_amount'      => 0,
            'payment_method'  => 'cash',
            'amount_tendered' => 10000.0,
            'completed_at'    => '2026-09-01 10:00:00',
            'payments'        => null,
        ]],
    ])->assertSuccessful();

    // Previously zero: the sync path never recognised revenue at all, so money
    // taken with no signal never reached the accounts.
    expect((float) FinancialLedgerEntry::where('direction', 'in')->sum('amount'))->toBe(10000.0);
});

it('still defers the debt slice on a synced credit sale', function () {
    $ctx = reversalContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'     => 'till-debt-1',
            'customer_id'    => $ctx['customer']->id,
            'items'          => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget',
                'unit_price' => 10000.0, 'quantity' => 1, 'total' => 10000.0,
            ]],
            'total'          => 10000.0,
            'vat_amount'     => 0,
            'payment_method' => 'debt',
            'completed_at'   => '2026-09-01 10:00:00',
            'payments'       => null,
        ]],
    ])->assertSuccessful();

    // The charge lands, the revenue does not — cash-basis, same as the counter.
    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0)
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

// ─── Phone-less customers are distinct people ───────────────────────────

it('does not merge two customers who both have no phone', function () {
    $ctx = reversalContext();
    Sanctum::actingAs($ctx['owner']);

    $first = $this->postJson('/api/pos/customers', [
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Walk-in Chidi',
    ])->assertSuccessful()->json();

    $second = $this->postJson('/api/pos/customers', [
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Walk-in Ngozi',
    ])->assertSuccessful()->json();

    // Two different people. Charging a debt to the wrong one looks perfectly
    // consistent in the ledger and is impossible to spot afterwards.
    expect($second['id'])->not->toBe($first['id'])
        ->and(PosCustomer::where('vendor_id', $ctx['vendor']->id)->whereNull('phone')->count())->toBe(2);
});

it('still reuses an existing customer when the phone matches', function () {
    $ctx = reversalContext();
    Sanctum::actingAs($ctx['owner']);

    $first = $this->postJson('/api/pos/customers', [
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Ada', 'phone' => '08099887766',
    ])->json();

    $again = $this->postJson('/api/pos/customers', [
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '08099887766',
    ])->json();

    // The same person must not accumulate three records and three balances.
    expect($again['id'])->toBe($first['id']);
});
