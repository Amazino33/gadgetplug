<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function idempotencyVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Idem Store ' . uniqid()]);
    $category = Category::create(['name' => 'Idem Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Idem Product',
        'price'          => 2500,
        'stock_quantity' => 50,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

function idemPayload(array $data, string $offlineId, int $qty = 1): array
{
    return [
        'vendor_id'  => $data['vendor']->id,
        'offline_id' => $offlineId,
        'items'      => [[
            'product_id'   => $data['product']->id,
            'product_name' => 'Idem Product',
            'unit_price'   => 2500,
            'quantity'     => $qty,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 10000,
        'payments'        => null,
    ];
}

// The till re-sends a checkout whenever it did not see a response — a dropped
// connection, a timeout, a deadlock killed mid-flight. That must not ring the
// sale up a second time.
test('re-posting the same checkout returns the original sale instead of a second one', function () {
    $data = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $first = $this->postJson('/api/pos/sales', idemPayload($data, 'till-abc-123'))->assertCreated();

    $this->postJson('/api/pos/sales', idemPayload($data, 'till-abc-123'))
        ->assertOk()
        ->assertJsonPath('reference', $first->json('reference'));

    expect(PosSale::count())->toBe(1);
});

test('a replay does not deduct the stock twice', function () {
    $data = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/sales', idemPayload($data, 'till-stock-1', 3))->assertCreated();
    expect($data['product']->fresh()->stock_quantity)->toBe(47);

    $this->postJson('/api/pos/sales', idemPayload($data, 'till-stock-1', 3))->assertOk();

    expect($data['product']->fresh()->stock_quantity)->toBe(47);
});

// The reference must match what PosSyncController derives, or the offline
// queue's own duplicate check cannot see a sale this endpoint already wrote —
// which is exactly how the duplicates got in.
test('the reference matches the scheme the offline sync deduplicates on', function () {
    $data = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $offlineId = 'till-scheme-9';
    $expected  = 'POS-' . strtoupper(substr(md5($offlineId), 0, 8));

    $this->postJson('/api/pos/sales', idemPayload($data, $offlineId))
        ->assertCreated()
        ->assertJsonPath('reference', $expected);
});

test('a queued sale that already went through online is skipped by the sync', function () {
    $data = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $offlineId = 'till-both-paths';

    // Went through online, but the till never saw the response
    $this->postJson('/api/pos/sales', idemPayload($data, $offlineId))->assertCreated();

    // ...so it queued it, and the sync sends it on
    $this->postJson('/api/pos/sync', [
        'vendor_id' => $data['vendor']->id,
        'sales'     => [[
            'offline_id'      => $offlineId,
            'items'           => [[
                'product_id'   => $data['product']->id,
                'product_name' => 'Idem Product',
                'unit_price'   => 2500,
                'quantity'     => 1,
            ]],
            'payment_method'  => 'cash',
            'total'           => 2500,
            'amount_tendered' => 10000,
            'completed_at'    => now()->toIso8601String(),
        ]],
    ])->assertOk();

    expect(PosSale::count())->toBe(1)
        ->and($data['product']->fresh()->stock_quantity)->toBe(49);
});

test('two different checkouts are still two sales', function () {
    $data = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/sales', idemPayload($data, 'till-one'))->assertCreated();
    $this->postJson('/api/pos/sales', idemPayload($data, 'till-two'))->assertCreated();

    expect(PosSale::count())->toBe(2);
});

test('a sale without an offline id still works', function () {
    $data    = idempotencyVendor();
    Sanctum::actingAs($data['owner']);

    $payload = idemPayload($data, 'unused');
    unset($payload['offline_id']);

    $this->postJson('/api/pos/sales', $payload)->assertCreated();

    expect(PosSale::count())->toBe(1);
});
