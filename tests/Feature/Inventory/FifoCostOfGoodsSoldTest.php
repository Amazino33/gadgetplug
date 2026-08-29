<?php

use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Phase 2: a sale is costed at what the units it consumed actually cost, not
// at whatever the product's single cost_price happened to be that day.

function cogsContext(): array
{
    $ctx = layersContext();

    // Two deliveries at different prices, oldest first.
    receiveBatch($ctx, 10, 1000.0);
    receiveBatch($ctx, 10, 1500.0);

    // Selling has to be allowed at a price the floor accepts.
    $ctx['product']->refresh();
    $ctx['product']->update(['price' => 5000, 'allow_pos_price_override' => false]);

    return $ctx;
}

function sellAtTill(array $ctx, int $quantity): array
{
    Sanctum::actingAs($ctx['owner']);

    return test()->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'unit_price'   => 5000.0,
            'quantity'     => $quantity,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 5000.0 * $quantity,
        'vat_rate'        => 0,
        'payments'        => null,
    ])->json();
}

function cogsFor(array $ctx): float
{
    return app(FinancialReportService::class)
        ->report($ctx['vendor']->id, now()->subDay(), now()->addDay())['product_cost'];
}

test('a sale drawing from one batch is costed at that batch', function () {
    $ctx = cogsContext();

    sellAtTill($ctx, 4);

    // 4 x 1,000, the oldest batch.
    expect(cogsFor($ctx))->toBe(4000.0);
});

test('a sale spanning two batches is costed at both, not at an average of neither', function () {
    $ctx = cogsContext();

    sellAtTill($ctx, 12);

    // 10 x 1,000 + 2 x 1,500. The old snapshot would have said 12 x 1,500.
    expect(cogsFor($ctx))->toBe(13000.0);
});

test('the real batch cost is written onto the sale line', function () {
    $ctx = cogsContext();

    $sale = sellAtTill($ctx, 12);
    $line = PosSaleItem::where('pos_sale_id', $sale['id'])->first();

    expect((float) $line->cost_total)->toBe(13000.0);
});

test('profit reflects what the goods actually cost', function () {
    $ctx = cogsContext();

    sellAtTill($ctx, 12);

    $report = app(FinancialReportService::class)
        ->report($ctx['vendor']->id, now()->subDay(), now()->addDay());

    // 12 x 5,000 sold, 13,000 of goods consumed.
    expect($report['revenue'])->toBe(60000.0)
        ->and($report['product_cost'])->toBe(13000.0)
        ->and($report['net_profit'])->toBe(47000.0);
});

test('what was sold and what is left add up to what was bought', function () {
    $ctx = cogsContext();

    sellAtTill($ctx, 12);

    $sold = cogsFor($ctx);
    $left = App\Services\Inventory\StockValuation::forVendor($ctx['vendor']->id)['value'];

    // (10 x 1,000) + (10 x 1,500) = 25,000 came in and none of it evaporated.
    expect($sold + $left)->toBe(25000.0);
});

test('two lines of the same product on one cart are each costed on their own', function () {
    $ctx = cogsContext();
    Sanctum::actingAs($ctx['owner']);

    // The same product twice, which the line-keyed write-back has to keep apart.
    $sale = test()->postJson('/api/pos/sales', [
        'vendor_id' => $ctx['vendor']->id,
        'items'     => [
            ['product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget', 'unit_price' => 5000.0, 'quantity' => 6],
            ['product_id' => $ctx['product']->id, 'product_name' => 'Credit Widget', 'unit_price' => 5000.0, 'quantity' => 6],
        ],
        'payment_method'  => 'cash',
        'amount_tendered' => 60000.0,
        'vat_rate'        => 0,
        'payments'        => null,
    ])->json();

    $lines = PosSaleItem::where('pos_sale_id', $sale['id'])->orderBy('id')->get();

    // First line takes 6 from the 1,000 batch. Second takes the last 4 of it
    // plus 2 from the 1,500 batch.
    expect($lines)->toHaveCount(2)
        ->and((float) $lines[0]->cost_total)->toBe(6000.0)
        ->and((float) $lines[1]->cost_total)->toBe(7000.0)
        ->and(cogsFor($ctx))->toBe(13000.0);
});

test('a sale made before batches existed still reports its old cost', function () {
    $ctx = cogsContext();

    // A line with no cost_total, exactly as every historic row will look.
    $sale = PosSale::create([
        'reference' => 'POS-OLD-' . uniqid(),
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'cashier_id' => $ctx['owner']->id,
        'subtotal' => 5000, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => 5000,
        'payment_method' => 'cash', 'amount_tendered' => 5000, 'change_given' => 0,
        'status' => 'completed', 'completed_at' => now(),
    ]);
    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $ctx['product']->id,
        'product_name' => 'Credit Widget', 'unit_price' => 5000, 'unit_cost' => 900,
        'cost_total' => null, 'quantity' => 2, 'discount_amount' => 0, 'total' => 10000,
    ]);

    // Falls back to 2 x 900 rather than reporting nothing.
    expect(cogsFor($ctx))->toBe(1800.0);
});

test('returning part of a sale reverses that share of what it really cost', function () {
    $ctx = cogsContext();

    $sale = sellAtTill($ctx, 12);

    // Hand back 6 of the 12. The line cost 13,000, so 6/12 of it comes back.
    test()->postJson("/api/pos/sales/{$sale['id']}/return", [
        'items'         => [['product_id' => $ctx['product']->id, 'quantity' => 6]],
        'refund_method' => 'cash',
    ])->assertSuccessful();

    expect(cogsFor($ctx))->toBe(13000.0 - 6500.0);
});
