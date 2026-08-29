<?php

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Returned goods used to stay in revenue at full value while the stock went
// back on the shelf, so the books showed the same item both sold and held.
// A return is now contra-revenue in the period it happened.

function returnsContext(): array
{
    $ctx = debtTenderContext();

    $ctx['sale'] = PosSale::create([
        'reference'       => 'POS-RET-' . uniqid(),
        'vendor_id'       => $ctx['vendor']->id,
        'store_id'        => $ctx['store']->id,
        'cashier_id'      => $ctx['owner']->id,
        'subtotal'        => 10000, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => 10000,
        'payment_method'  => 'cash', 'amount_tendered' => 10000, 'change_given' => 0,
        'status'          => 'completed', 'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $ctx['sale']->id, 'product_id' => $ctx['product']->id,
        'product_name' => 'Credit Widget', 'unit_price' => 10000, 'unit_cost' => 6000,
        'quantity' => 1, 'discount_amount' => 0, 'total' => 10000,
    ]);

    return $ctx;
}

function recordReturn(array $ctx, int $quantity, float $total, ?string $at = null): PosReturn
{
    $return = PosReturn::create([
        'reference'        => 'RET-' . uniqid(),
        'vendor_id'        => $ctx['vendor']->id,
        'original_sale_id' => $ctx['sale']->id,
        'cashier_id'       => $ctx['owner']->id,
        'return_items'     => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'quantity'     => $quantity,
            'unit_price'   => 10000.0,
            'total'        => $total,
        ]],
        'refund_amount'    => $total,
        'refund_method'    => 'cash',
    ]);

    if ($at) {
        $return->forceFill(['created_at' => $at])->saveQuietly();
    }

    return $return;
}

function reportFor(array $ctx, $from = null, $to = null): array
{
    return app(FinancialReportService::class)->report(
        $ctx['vendor']->id,
        $from ?? now()->subDay(),
        $to ?? now()->addDay(),
    );
}

test('a fully returned sale nets to no revenue and no cost', function () {
    $ctx = returnsContext();

    expect(reportFor($ctx)['revenue'])->toBe(10000.0);

    recordReturn($ctx, 1, 10000.0);

    $report = reportFor($ctx);

    expect($report['revenue'])->toBe(0.0)
        ->and($report['product_cost'])->toBe(0.0)
        ->and($report['net_profit'])->toBe(0.0);
});

test('a partial return reverses only the units handed back', function () {
    $ctx = returnsContext();
    $ctx['sale']->items()->update(['quantity' => 2, 'total' => 20000]);
    $ctx['sale']->update(['subtotal' => 20000, 'total' => 20000]);

    recordReturn($ctx, 1, 10000.0);

    $report = reportFor($ctx);

    // Two sold, one back: one sale's worth of revenue and one unit of cost.
    expect($report['revenue'])->toBe(10000.0)
        ->and($report['product_cost'])->toBe(6000.0);
});

test('the cost reversed is the one the sale booked, not the product\'s cost today', function () {
    $ctx = returnsContext();

    // Restocking after the sale moves cost_price. The reversal must still use
    // the 6,000 the sale snapshotted, or the pair leaves a residue in profit.
    $ctx['product']->update(['cost_price' => 9999]);

    recordReturn($ctx, 1, 10000.0);

    expect(reportFor($ctx)['product_cost'])->toBe(0.0);
});

test('a return lands in its own period and never rewrites the period of the sale', function () {
    $ctx = returnsContext();

    // Sold today, returned three days later.
    recordReturn($ctx, 1, 10000.0, now()->addDays(3)->toDateTimeString());

    $saleWeek = reportFor($ctx, now()->subDay(), now()->addDay());
    $returnWeek = reportFor($ctx, now()->addDays(2), now()->addDays(4));

    // The period the sale was made in is untouched — a month already read and
    // closed does not change underneath anyone.
    expect($saleWeek['revenue'])->toBe(10000.0)
        ->and($saleWeek['product_cost'])->toBe(6000.0);

    // The reversal shows up where the goods actually came back.
    expect($returnWeek['revenue'])->toBe(-10000.0)
        ->and($returnWeek['product_cost'])->toBe(-6000.0);

    // Across both, they cancel exactly.
    $whole = reportFor($ctx, now()->subDay(), now()->addDays(4));
    expect($whole['revenue'])->toBe(0.0)
        ->and($whole['net_profit'])->toBe(0.0);
});

test('a voided sale is still excluded outright, and is not double-reversed by a return', function () {
    $ctx = returnsContext();
    $ctx['sale']->update(['status' => 'voided']);

    expect(reportFor($ctx)['revenue'])->toBe(0.0);
});

test('a return whose sale never snapshotted a cost flags the figure instead of guessing', function () {
    $ctx = returnsContext();
    $ctx['sale']->items()->update(['unit_cost' => null]);

    recordReturn($ctx, 1, 10000.0);

    $report = reportFor($ctx);

    expect($report['cost_is_estimated'])->toBeTrue();
});

test('returns from another vendor never touch this vendor\'s figures', function () {
    $ctx = returnsContext();
    $other = returnsContext();

    recordReturn($other, 1, 10000.0);

    expect(reportFor($ctx)['revenue'])->toBe(10000.0);
});
