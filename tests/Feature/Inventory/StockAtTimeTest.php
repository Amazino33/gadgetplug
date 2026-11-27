<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\StockAtTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Stock at a past moment is the basis of counting while the shop keeps
 * selling: a count is compared with what the shelf held when it was counted,
 * and every movement since is kept rather than undone.
 */
function satContext(int $onShelf = 10): array
{
    $vendor = Vendor::create([
        'user_id'                => User::factory()->create()->id,
        'name'                   => 'Stock At Time '.uniqid(),
        'pos_min_margin_percent' => 0,
    ]);

    $store   = Store::create(['vendor_id' => $vendor->id, 'name' => 'Counted Branch']);
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $store->id,
        'category_id'    => Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Counted Drink '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 400,
        'stock_quantity' => 0,
        'status'         => 'published',
        'show_in_pos'    => true,
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $store->id],
        ['quantity' => $onShelf, 'reserved' => 0],
    );

    return compact('vendor', 'store', 'product');
}

function satMove(array $ctx, int|float $change, string $type = 'pos_sale', ?Carbon $occurredAt = null): void
{
    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id,
        quantityChanged: $change,
        transactionType: $type,
        store: $ctx['store']->id,
        occurredAt: $occurredAt,
    );
}

afterEach(fn () => Carbon::setTestNow());

test('a sale after the moment is added back to find what the shelf held then', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $countedAt = now();

    Carbon::setTestNow('2026-10-14 10:20:00');
    satMove($ctx, -2);

    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(10);
});

test('a delivery and a return after the moment are taken off again', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $countedAt = now();

    Carbon::setTestNow('2026-10-14 11:00:00');
    satMove($ctx, 6, 'restock');
    satMove($ctx, 1, 'pos_return');

    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(10);
});

test('a movement before the moment is part of what the shelf held', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 09:00:00');
    satMove($ctx, -3);

    Carbon::setTestNow('2026-10-14 10:00:00');

    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, now()))->toBe(7);
});

test('an offline sale rung before the moment but synced after it counts as before', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $countedAt = now();

    // Synced at 10:40, rung at 09:50.
    Carbon::setTestNow('2026-10-14 10:40:00');
    satMove($ctx, -1, 'pos_sale', Carbon::parse('2026-10-14 09:50:00'));

    // The shelf held 9 at 10:00 — that one had already gone.
    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(9);
});

test('a reservation earmarks stock without moving the shelf', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $countedAt = now();

    Carbon::setTestNow('2026-10-14 10:30:00');
    InventoryLedger::create([
        'vendor_id'        => $ctx['vendor']->id,
        'store_id'         => $ctx['store']->id,
        'product_id'       => $ctx['product']->id,
        'transaction_type' => 'reserved',
        'quantity_change'  => 4,
    ]);

    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(10);
});

test('a half sold after the moment is added back as a half', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $countedAt = now();

    Carbon::setTestNow('2026-10-14 10:20:00');
    satMove($ctx, -0.5);

    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(10)
        ->and(StockAtTime::at($ctx['product']->id, $ctx['store']->id, now()))->toBe(9.5);
});

test('movement between two moments is the net of what happened in between', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 10:00:00');
    $a = now();
    Carbon::setTestNow('2026-10-14 10:30:00');
    satMove($ctx, -2);
    Carbon::setTestNow('2026-10-14 11:00:00');
    $b = now();
    Carbon::setTestNow('2026-10-14 11:30:00');
    satMove($ctx, -5);

    expect(StockAtTime::movedBetween($ctx['product']->id, $ctx['store']->id, $a, $b))->toBe(-2);
});

test('every ledger row is stamped with when it happened', function () {
    $ctx = satContext(10);
    Carbon::setTestNow('2026-10-14 12:00:00');
    satMove($ctx, -1);

    expect(InventoryLedger::sole()->occurred_at->toDateTimeString())->toBe('2026-10-14 12:00:00');
});

test('an offline sale synced late carries the till time, not the sync time', function () {
    $ctx     = satContext(10);
    $cashier = User::factory()->create();
    $ctx['vendor']->users()->attach($cashier->id);
    $cashier->stores()->attach($ctx['store']->id);
    Sanctum::actingAs($cashier, ['pos']);

    Carbon::setTestNow('2026-10-14 10:40:00');

    $this->postJson('/api/pos/sync', ['vendor_id' => $ctx['vendor']->id, 'sales' => [[
        'offline_id'      => 'OFF-'.uniqid(),
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => $ctx['product']->name,
            'unit_price'   => 1000,
            'quantity'     => 1,
            'total'        => 1000,
        ]],
        'discount_amount' => 0,
        'vat_rate'        => 0,
        'subtotal'        => 1000,
        'payment_method'  => 'cash',
        'total'           => 1000,
        'vat_amount'      => 0,
        'amount_tendered' => 1000,
        'change_given'    => 0,
        'completed_at'    => '2026-10-14T09:50:00+00:00',
    ]]])->assertJsonPath('results.0.status', 'synced');

    $ledger = InventoryLedger::where('transaction_type', 'pos_sale')->sole();

    expect($ledger->occurred_at->toDateTimeString())->toBe('2026-10-14 09:50:00')
        ->and($ledger->created_at->toDateTimeString())->toBe('2026-10-14 10:40:00');
});

test('a till clock running ahead cannot stamp a sale in the future', function () {
    $ctx     = satContext(10);
    $cashier = User::factory()->create();
    $ctx['vendor']->users()->attach($cashier->id);
    $cashier->stores()->attach($ctx['store']->id);
    Sanctum::actingAs($cashier, ['pos']);

    Carbon::setTestNow('2026-10-14 10:40:00');

    $this->postJson('/api/pos/sync', ['vendor_id' => $ctx['vendor']->id, 'sales' => [[
        'offline_id'      => 'OFF-'.uniqid(),
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => $ctx['product']->name,
            'unit_price'   => 1000,
            'quantity'     => 1,
            'total'        => 1000,
        ]],
        'discount_amount' => 0,
        'vat_rate'        => 0,
        'subtotal'        => 1000,
        'payment_method'  => 'cash',
        'total'           => 1000,
        'vat_amount'      => 0,
        'amount_tendered' => 1000,
        'change_given'    => 0,
        'completed_at'    => '2026-10-14T13:00:00+00:00',
    ]]])->assertJsonPath('results.0.status', 'synced');

    expect(InventoryLedger::where('transaction_type', 'pos_sale')->sole()->occurred_at->toDateTimeString())
        ->toBe('2026-10-14 10:40:00');
});

test('a sale rung before the count but synced hours later is already off the shelf at the count', function () {
    $ctx     = satContext(10);
    $cashier = User::factory()->create();
    $ctx['vendor']->users()->attach($cashier->id);
    $cashier->stores()->attach($ctx['store']->id);
    Sanctum::actingAs($cashier, ['pos']);

    // The item was counted at 10:00. The till was offline from before then.
    $countedAt = Carbon::parse('2026-11-24 10:00:00');

    // The sale was rung at 09:55 and reached the server only at 13:00.
    Carbon::setTestNow('2026-11-24 13:00:00');

    $this->postJson('/api/pos/sync', ['vendor_id' => $ctx['vendor']->id, 'sales' => [[
        'offline_id'      => 'OFF-'.uniqid(),
        'items'           => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => $ctx['product']->name,
            'unit_price'   => 1000,
            'quantity'     => 1,
            'total'        => 1000,
        ]],
        'discount_amount' => 0,
        'vat_rate'        => 0,
        'subtotal'        => 1000,
        'payment_method'  => 'cash',
        'total'           => 1000,
        'vat_amount'      => 0,
        'amount_tendered' => 1000,
        'change_given'    => 0,
        'completed_at'    => '2026-11-24T09:55:00+00:00',
    ]]])->assertJsonPath('results.0.status', 'synced');

    // Live stock is 9, and so was the shelf at 10:00: the sale had already
    // happened. Counting it as after 10:00 (by sync time) would say 10, and
    // a count of 9 would then post a false shortage of 1.
    expect(StockAtTime::at($ctx['product']->id, $ctx['store']->id, Carbon::now()))->toBe(9)
        ->and(StockAtTime::at($ctx['product']->id, $ctx['store']->id, $countedAt))->toBe(9)
        ->and(StockAtTime::at($ctx['product']->id, $ctx['store']->id, Carbon::parse('2026-11-24 09:50:00')))->toBe(10);
});
