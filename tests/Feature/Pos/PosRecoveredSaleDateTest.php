<?php

use App\Models\PosSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// A sale the server refused can be sent back to the till, corrected and rung
// again. It has to land on the day the goods actually left, not the day someone
// got round to fixing it — otherwise that day's takings are short by the sale
// and this day's are over by it, and neither drawer reconciles.
//
// The till therefore puts a corrected sale back through the sync queue rather
// than the live endpoint, because only the queue keeps the date it carries.
// These pin down that difference, which is the whole reason for the detour.

function recoveredSaleContext(): array
{
    $ctx = debtTenderContext();
    Sanctum::actingAs($ctx['owner']);

    return $ctx;
}

function syncPayload(array $ctx, string $completedAt, array $overrides = []): array
{
    return array_merge([
        'offline_id'      => 'recovered-' . uniqid(),
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'unit_price'   => 10000.0,
            'quantity'     => 1,
            'discount_amount' => 0,
            'total'        => 10000.0,
        ]],
        'discount_amount' => 0,
        'vat_rate'        => 0,
        'subtotal'        => 10000.0,
        'vat_amount'      => 0,
        'total'           => 10000.0,
        'payment_method'  => 'cash',
        'amount_tendered' => 10000.0,
        'change_given'    => 0,
        'completed_at'    => $completedAt,
    ], $overrides);
}

test('a sale synced from the till keeps the day it was rung, not the day it arrived', function () {
    $ctx = recoveredSaleContext();

    $yesterday = now()->subDay()->setTime(14, 30);

    $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [syncPayload($ctx, $yesterday->toDateTimeString())],
    ])->assertSuccessful();

    $sale = PosSale::latest('id')->first();

    expect($sale)->not->toBeNull()
        ->and($sale->completed_at->toDateTimeString())->toBe($yesterday->toDateTimeString());
});

test('the same sale rung on the live till is stamped now, which is why a correction takes the queue', function () {
    $ctx = recoveredSaleContext();

    $yesterday = now()->subDay()->setTime(14, 30);

    // The live endpoint deliberately ignores any date it is handed — a cashier
    // ringing a sale now is selling now. That is correct for an ordinary sale
    // and wrong for a correction, which is the whole reason the till routes a
    // recovered sale through the queue instead.
    $this->postJson('/api/pos/sales', [
        'vendor_id'       => $ctx['vendor']->id,
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'unit_price'   => 10000.0,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 10000,
        'vat_rate'        => 0,
        'payments'        => null,
        'completed_at'    => $yesterday->toDateTimeString(),
    ])->assertSuccessful();

    $sale = PosSale::latest('id')->first();

    expect($sale->completed_at->isToday())->toBeTrue();
});

test('a corrected sale still has to clear the price floor — the date is all that is carried over', function () {
    $ctx = recoveredSaleContext();

    // Below the floor: this product does not allow negotiation, so its floor is
    // the list price. Backdating must not become a way past that.
    $response = $this->postJson('/api/pos/sync', [
        'vendor_id' => $ctx['vendor']->id,
        'sales'     => [syncPayload($ctx, now()->subDay()->toDateTimeString(), [
            'items' => [[
                'product_id'      => $ctx['product']->id,
                'product_name'    => 'Credit Widget',
                'unit_price'      => 2000.0,
                'quantity'        => 1,
                'discount_amount' => 0,
                'total'           => 2000.0,
            ]],
            'subtotal'        => 2000.0,
            'total'           => 2000.0,
            'amount_tendered' => 2000.0,
        ])],
    ]);

    $response->assertSuccessful();

    expect($response->json('results.0.status'))->toBe('rejected')
        ->and(PosSale::count())->toBe(0);
});
