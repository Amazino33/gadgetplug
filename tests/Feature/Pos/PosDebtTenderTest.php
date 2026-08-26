<?php

use App\Models\Category;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ─── Debt-only sale ─────────────────────────────────────────────────────

it('records a full-value charge for a sale paid entirely on credit', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    $sale = PosSale::firstOrFail();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);

    $charge = PosCustomerLedgerEntry::where('source_id', $sale->id)->firstOrFail();

    expect($charge->direction)->toBe('charge')
        ->and((float) $charge->amount)->toBe(10000.0)
        ->and($charge->store_id)->toBe($ctx['store']->id)
        ->and($charge->created_by)->toBe($ctx['owner']->id)
        ->and($charge->vendor_id)->toBe($ctx['vendor']->id);
});

it('writes a tender row even when debt is the only tender', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    $sale = PosSale::firstOrFail();
    $rows = PosSalePayment::where('pos_sale_id', $sale->id)->get();

    // The whole point: downstream code reads debt from tenders, never from the
    // sale-level columns.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->method)->toBe('debt')
        ->and((float) $rows->first()->amount)->toBe(10000.0);
});

it('records nothing tendered on a wholly credit sale', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    expect((float) PosSale::firstOrFail()->amount_tendered)->toBe(0.0);
});

// ─── Split cash + debt ──────────────────────────────────────────────────

it('charges only the debt slice of a part-paid sale', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'split',
        'amount_tendered' => 10000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]))->assertSuccessful();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(6000.0);

    expect(PosCustomerLedgerEntry::count())->toBe(1)
        ->and((float) PosCustomerLedgerEntry::first()->amount)->toBe(6000.0);
});

it('creates no charge at all for a fully paid sale', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'payment_method'  => 'cash',
        'amount_tendered' => 10000.0,
    ]))->assertSuccessful();

    expect(PosCustomerLedgerEntry::count())->toBe(0)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(0.0);
});

// ─── Customer requirement ───────────────────────────────────────────────

it('refuses a credit sale with no customer attached', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, ['customer_id' => null]))
        ->assertStatus(422);

    expect(PosSale::count())->toBe(0)
        ->and(PosCustomerLedgerEntry::count())->toBe(0);
});

it('refuses a split containing a debt leg with no customer attached', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'customer_id'     => null,
        'payment_method'  => 'split',
        'amount_tendered' => 10000.0,
        'payments'        => [
            ['method' => 'cash', 'amount' => 4000.0],
            ['method' => 'debt', 'amount' => 6000.0],
        ],
    ]))->assertStatus(422);

    expect(PosSale::count())->toBe(0);
});

it('still allows an anonymous sale when nothing is owed', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx, [
        'customer_id'     => null,
        'payment_method'  => 'cash',
        'amount_tendered' => 10000.0,
    ]))->assertSuccessful();
});

// ─── Offline sync ───────────────────────────────────────────────────────

it('creates the charge when an offline credit sale syncs', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'     => 'till-1-0001',
            'reference'      => 'POS-OFFLINE1',
            'customer_id'    => $ctx['customer']->id,
            'items'          => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget',
                'unit_price' => 10000.0, 'quantity' => 1, 'total' => 10000.0,
            ]],
            'total'          => 10000.0,
            'vat_amount'     => 0,
            'payment_method' => 'debt',
            'completed_at'   => '2026-08-20 14:00:00',
            'payments'       => null,
        ]],
    ])->assertSuccessful();

    $sale = PosSale::where('customer_id', $ctx['customer']->id)->firstOrFail();

    expect(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);

    $charge = PosCustomerLedgerEntry::where('source_id', $sale->id)->firstOrFail();

    // Dated when it was rung up at the counter, not when it reached the server.
    expect($charge->occurred_at->toDateString())->toBe('2026-08-20')
        ->and($charge->store_id)->toBe($ctx['store']->id);
});

it('does not double the debt when the same offline sale syncs twice', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $payload = [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [[
            'offline_id'     => 'till-1-0002',
            'reference'      => 'POS-OFFLINE2',
            'customer_id'    => $ctx['customer']->id,
            'items'          => [[
                'product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget',
                'unit_price' => 10000.0, 'quantity' => 1, 'total' => 10000.0,
            ]],
            'total'          => 10000.0,
            'vat_amount'     => 0,
            'payment_method' => 'debt',
            'completed_at'   => '2026-08-20 14:00:00',
            'payments'       => null,
        ]],
    ];

    $this->postJson('/api/pos/sync', $payload)->assertSuccessful();
    $this->postJson('/api/pos/sync', $payload)->assertSuccessful();

    expect(PosCustomerLedgerEntry::count())->toBe(1)
        ->and(app(CustomerDebtService::class)->outstanding($ctx['customer']->id))->toBe(10000.0);
});

// ─── Exposure endpoint ──────────────────────────────────────────────────

it('reports what a customer owes to the till', function () {
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    $this->postJson('/api/pos/sales', debtSalePayload($ctx))->assertSuccessful();

    $this->getJson("/api/pos/customers/{$ctx['customer']->id}/outstanding")
        ->assertSuccessful()
        ->assertJson(['customer_id' => $ctx['customer']->id, 'outstanding' => 10000.0]);
});

it('does not leak another vendor customer balance', function () {
    $mine   = debtTenderContext();
    $theirs = debtTenderContext();

    Sanctum::actingAs($mine['owner']);

    $this->getJson("/api/pos/customers/{$theirs['customer']->id}/outstanding")
        ->assertNotFound();
});
