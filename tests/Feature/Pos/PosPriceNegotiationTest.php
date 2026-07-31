<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pos\PosPriceFloor;
use Laravel\Sanctum\Sanctum;

// A cashier may haggle at the till, but never below what the goods cost.
// These cover the floor itself, the cart-discount route around it, products
// whose price is locked, and the offline-sync door into the same logic.

function negotiationContext(array $vendorAttributes = [], array $productAttributes = []): array
{
    $owner = User::factory()->create();

    $vendor = Vendor::create(array_merge([
        'user_id'                => $owner->id,
        'name'                   => 'Leisure Hub',
        'pos_min_margin_percent' => 0,
    ], $vendorAttributes));

    $category = Category::create(['name' => 'Chargers']);

    $product = Product::create(array_merge([
        'vendor_id'                => $vendor->id,
        'category_id'              => $category->id,
        'name'                     => 'SHPLUS 60W Charger',
        'price'                    => 5300,
        'cost_price'               => 2570,
        'allow_pos_price_override' => true,
        'stock_quantity'           => 50,
        'reserved_stock'           => 0,
        'status'                   => 'published',
        'show_in_pos'              => true,
        'show_online'              => true,
    ], $productAttributes));

    return ['owner' => $owner, 'vendor' => $vendor, 'product' => $product];
}

function salePayload(array $context, array $overrides = []): array
{
    return array_merge([
        'vendor_id'       => $context['vendor']->id,
        'items'           => [[
            'product_id'   => $context['product']->id,
            'product_name' => $context['product']->name,
            'unit_price'   => 5300,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 100000,
        'vat_rate'        => 0,
    ], $overrides);
}

// ── The floor itself ────────────────────────────────────────────────────────

it('lets a price be haggled down to cost but not a naira below it', function () {
    $context = negotiationContext();
    Sanctum::actingAs($context['owner']);

    $atCost = salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2570,
            'quantity'     => 1,
        ]],
    ]);

    $this->postJson('/api/pos/sales', $atCost)->assertSuccessful();

    $belowCost = salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2569,
            'quantity'     => 1,
        ]],
    ]);

    $this->postJson('/api/pos/sales', $belowCost)
        ->assertStatus(422)
        ->assertJsonValidationErrors('items');
});

it('keeps the margin the vendor insists on, not just the cost', function () {
    // 10% on a 2,570 cost puts the floor at 2,827 — cost alone is no longer enough.
    $context = negotiationContext(['pos_min_margin_percent' => 10]);
    Sanctum::actingAs($context['owner']);

    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2800,
            'quantity'     => 1,
        ]],
    ]))->assertStatus(422);

    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2827,
            'quantity'     => 1,
        ]],
    ]))->assertSuccessful();
});

// ── The way round the floor ─────────────────────────────────────────────────

it('stops a cart discount dragging a sale under the floor every line just passed', function () {
    $context = negotiationContext();
    Sanctum::actingAs($context['owner']);

    // Three units haggled to exactly the floor: every line passes on its own.
    // The cart discount is then taken off the basket afterwards, which is where
    // the money actually leaks if nothing re-checks the total.
    $response = $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2570,
            'quantity'     => 3,
        ]],
        'discount_amount' => 1000,
        'discount_type'   => 'fixed',
        'discount_scope'  => 'cart',
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('discount_amount');

    expect(PosSale::count())->toBe(0);
});

it('still allows a cart discount that leaves the sale above the floor', function () {
    $context = negotiationContext();
    Sanctum::actingAs($context['owner']);

    // Three at full list is 15,900 against a floor total of 7,710 — a 1,000
    // discount has plenty of room and must not be blocked.
    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 5300,
            'quantity'     => 3,
        ]],
        'discount_amount' => 1000,
        'discount_type'   => 'fixed',
        'discount_scope'  => 'cart',
    ]))->assertSuccessful();
});

// ── Products that must not move ─────────────────────────────────────────────

it('refuses to discount a product whose price is locked', function () {
    $context = negotiationContext([], ['allow_pos_price_override' => false]);
    Sanctum::actingAs($context['owner']);

    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
    ]))->assertStatus(422);
});

it('refuses to negotiate a product that has no recorded cost', function () {
    // Nothing to measure a loss against, so the price simply cannot move.
    $context = negotiationContext([], ['cost_price' => null]);
    Sanctum::actingAs($context['owner']);

    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 4000,
            'quantity'     => 1,
        ]],
    ]))->assertStatus(422);
});

it('still sells at list price when the vendor prices at or below cost', function () {
    // A deliberate loss-leader: the floor must never rise above the vendor's
    // own list price, or the product becomes impossible to ring up at all.
    $context = negotiationContext([], ['price' => 2000, 'cost_price' => 2570]);
    Sanctum::actingAs($context['owner']);

    $this->postJson('/api/pos/sales', salePayload($context, [
        'items' => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'SHPLUS 60W Charger',
            'unit_price'   => 2000,
            'quantity'     => 1,
        ]],
    ]))->assertSuccessful();
});

// ── The offline door into the same rules ────────────────────────────────────

it('rejects a below-floor offline sale without losing the good ones beside it', function () {
    $context = negotiationContext();
    Sanctum::actingAs($context['owner']);

    $response = $this->postJson('/api/pos/sync', [
        'vendor_id' => $context['vendor']->id,
        'sales'     => [
            [
                'offline_id'     => 'good-one',
                'items'          => [[
                    'product_id'   => $context['product']->id,
                    'product_name' => 'SHPLUS 60W Charger',
                    'unit_price'   => 5300,
                    'quantity'     => 1,
                    'total'        => 5300,
                ]],
                'payment_method' => 'cash',
                'total'          => 5300,
                'completed_at'   => now()->toDateTimeString(),
            ],
            [
                'offline_id'     => 'below-floor',
                'items'          => [[
                    'product_id'   => $context['product']->id,
                    'product_name' => 'SHPLUS 60W Charger',
                    'unit_price'   => 1000,
                    'quantity'     => 1,
                    'total'        => 1000,
                ]],
                'payment_method' => 'cash',
                'total'          => 1000,
                'completed_at'   => now()->toDateTimeString(),
            ],
        ],
    ]);

    $response->assertSuccessful();

    $results = collect($response->json('results'))->keyBy('offline_id');

    expect($results['good-one']['status'])->toBe('synced')
        ->and($results['below-floor']['status'])->toBe('rejected');

    // The honest sale is banked; the bad one is held back for a human.
    expect(PosSale::count())->toBe(1);
});

// ── What the till is allowed to know ────────────────────────────────────────

it('sends the floor to the till but never what the goods cost', function () {
    $context = negotiationContext(['pos_min_margin_percent' => 10]);
    Sanctum::actingAs($context['owner']);

    $response = $this->getJson('/api/pos/products?vendor_id='.$context['vendor']->id);

    $response->assertSuccessful();

    $payload = $response->json();

    expect($payload)->toHaveCount(1)
        ->and($payload[0]['min_price'])->toEqual(2827.0)
        ->and($payload[0]['can_negotiate'])->toBeTrue()
        ->and($payload[0])->not->toHaveKey('cost_price')
        ->and($payload[0])->not->toHaveKey('allow_pos_price_override');
});

it('tells the till there is nothing to negotiate on a locked product', function () {
    $context = negotiationContext([], ['allow_pos_price_override' => false]);
    Sanctum::actingAs($context['owner']);

    $payload = $this->getJson('/api/pos/products?vendor_id='.$context['vendor']->id)->json();

    expect($payload[0]['can_negotiate'])->toBeFalse()
        ->and($payload[0]['min_price'])->toEqual(5300.0);
});

// ── The floor calculation on its own ────────────────────────────────────────

it('works the floor out from cost, margin and the lock flag', function () {
    $floor = app(PosPriceFloor::class);

    $product = new Product(['price' => 5300, 'cost_price' => 2570, 'allow_pos_price_override' => true]);

    expect($floor->floorFor($product, 0))->toEqual(2570.0)
        ->and($floor->floorFor($product, 10))->toEqual(2827.0)
        ->and($floor->floorFor($product, 50))->toEqual(3855.0);

    $locked = new Product(['price' => 5300, 'cost_price' => 2570, 'allow_pos_price_override' => false]);
    expect($floor->floorFor($locked, 0))->toEqual(5300.0);

    $costless = new Product(['price' => 5300, 'cost_price' => null, 'allow_pos_price_override' => true]);
    expect($floor->floorFor($costless, 0))->toEqual(5300.0);
});
