<?php

use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Cash-basis revenue: only money actually collected is recognised at the till.
// The debt slice waits for repayment, so an open debt drags the period honestly
// rather than flattering it.

function recognitionContext(): array
{
    $ctx = debtTenderContext();

    // Both accounts exist, so "posted nothing" can never be confused with
    // "had nowhere to post it" — post() logs and returns when an account is
    // missing, which would look identical from the ledger's side.
    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Cash Drawer', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
    ]);
    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Bank', 'type' => 'bank',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    return $ctx;
}

function recognisedTotal(int $vendorId): float
{
    return (float) FinancialLedgerEntry::whereIn(
        'financial_account_id',
        FinancialAccount::where('vendor_id', $vendorId)->pluck('id')
    )->where('direction', 'in')->sum('amount');
}

it('recognises the full amount on an ordinary cash sale, unchanged', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'cash',
        'amount_tendered' => 10000.0,
    ]))->assertSuccessful();

    expect(recognisedTotal($ctx['vendor']->id))->toBe(10000.0);
});

it('recognises nothing at the till for a wholly credit sale', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    expect(recognisedTotal($ctx['vendor']->id))->toBe(0.0)
        ->and(FinancialLedgerEntry::count())->toBe(0);
});

it('recognises only the collected slice of a part-paid sale', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 4000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]))->assertSuccessful();

    // The 4,000 collected, not the 10,000 of goods that left.
    expect(recognisedTotal($ctx['vendor']->id))->toBe(4000.0)
        ->and(FinancialLedgerEntry::count())->toBe(1);
});

it('never routes a debt slice into the bank account', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 4000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]))->assertSuccessful();

    $bank = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'bank')->firstOrFail();

    // The trap this phase exists to close: post() maps anything non-cash to the
    // bank account, so an unguarded debt tender would have banked 6,000 nobody
    // handed over — and logged nothing, because post() swallows failures.
    expect(FinancialLedgerEntry::where('financial_account_id', $bank->id)->count())->toBe(0);
});

it('recognises a card and debt split as card only', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 0.0,
        'payments'        => [
            ['method' => 'card', 'amount' => 2500.0],
            ['method' => 'debt', 'amount' => 7500.0],
        ],
    ]))->assertSuccessful();

    expect(recognisedTotal($ctx['vendor']->id))->toBe(2500.0);
});

// ─── COGS is untouched by any of this ───────────────────────────────────

it('books the cost and moves the stock on a credit sale exactly as on a cash one', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $before = $ctx['product']->fresh()->stock_quantity;

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    $item = PosSaleItem::firstOrFail();

    // Cost snapshotted and stock gone, even though no money arrived — the goods
    // physically left the shelf, which is what stock-out means.
    expect((float) $item->unit_cost)->toBe(6000.0)
        ->and($ctx['product']->fresh()->stock_quantity)->toBe($before - 1);
});

it('books the same cost whether the sale was paid, part-paid or wholly owed', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method' => 'cash', 'amount_tendered' => 10000.0,
    ]))->assertSuccessful();

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 4000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]))->assertSuccessful();

    expect(PosSaleItem::pluck('unit_cost')->map(fn ($c) => (float) $c)->all())
        ->toBe([6000.0, 6000.0, 6000.0])
        ->and(PosSale::count())->toBe(3);

    // Revenue, by contrast, is only what came in: 10,000 + 0 + 4,000.
    expect(recognisedTotal($ctx['vendor']->id))->toBe(14000.0);
});

it('leaves the product cost alone so profit reporting still has it', function () {
    $ctx = recognitionContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    expect((float) Product::find($ctx['product']->id)->cost_price)->toBe(6000.0);
});
