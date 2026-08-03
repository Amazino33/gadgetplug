<?php

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

// The powerbank bug: a sale the server genuinely rejects (insufficient stock,
// a business-rule failure) must come back as a clear, honest error — not an
// opaque 500 that the till's frontend then treats identically to a dropped
// connection and silently queues forever.

function rejectionContext(array $productAttributes = []): array
{
    $owner = User::factory()->create();

    $vendor = Vendor::create([
        'user_id'                => $owner->id,
        'name'                   => 'Rejection Test Store',
        'pos_min_margin_percent' => 0,
    ]);

    $category = Category::create(['name' => 'Powerbanks']);

    $product = Product::create(array_merge([
        'vendor_id'                => $vendor->id,
        'category_id'              => $category->id,
        'name'                     => 'Powerbank 20000mAh',
        'price'                    => 8000,
        'cost_price'               => 5000,
        'allow_pos_price_override' => false,
        'stock_quantity'           => 1,
        'reserved_stock'           => 0,
        'status'                   => 'published',
        'show_in_pos'              => true,
    ], $productAttributes));

    return ['owner' => $owner, 'vendor' => $vendor, 'product' => $product];
}

it('rejects a sale for more stock than is on the shelf with a clear message, not a crash', function () {
    $context = rejectionContext(['stock_quantity' => 1]);
    Sanctum::actingAs($context['owner']);

    $response = $this->postJson('/api/pos/sales', [
        'vendor_id' => $context['vendor']->id,
        'items'     => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'Powerbank 20000mAh',
            'unit_price'   => 8000,
            'quantity'     => 5,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 40000,
        'vat_rate'        => 0,
    ]);

    $response->assertStatus(422)
        ->assertJson(['code' => 'sale_rejected'])
        ->assertJsonFragment(['message' => 'Insufficient stock for product: Powerbank 20000mAh']);
});

it('never persists a sale, sale item, or ledger row when the sale is rejected', function () {
    $context = rejectionContext(['stock_quantity' => 1]);
    Sanctum::actingAs($context['owner']);

    $this->postJson('/api/pos/sales', [
        'vendor_id' => $context['vendor']->id,
        'items'     => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'Powerbank 20000mAh',
            'unit_price'   => 8000,
            'quantity'     => 5,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 40000,
        'vat_rate'        => 0,
    ]);

    expect(PosSale::count())->toBe(0)
        ->and(InventoryLedger::where('product_id', $context['product']->id)->exists())->toBeFalse()
        ->and($context['product']->fresh()->stock_quantity)->toBe(1);
});

it('still completes a sale that stock genuinely covers', function () {
    $context = rejectionContext(['stock_quantity' => 10]);
    Sanctum::actingAs($context['owner']);

    $response = $this->postJson('/api/pos/sales', [
        'vendor_id' => $context['vendor']->id,
        'items'     => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'Powerbank 20000mAh',
            'unit_price'   => 8000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 20000,
        'vat_rate'        => 0,
    ]);

    $response->assertSuccessful();

    expect(PosSale::count())->toBe(1)
        ->and($context['product']->fresh()->stock_quantity)->toBe(8);
});

it('accepts a split-payment sale through the offline sync endpoint', function () {
    $context = rejectionContext(['stock_quantity' => 10]);
    Sanctum::actingAs($context['owner']);

    $response = $this->postJson('/api/pos/sync', [
        'vendor_id' => $context['vendor']->id,
        'sales'     => [[
            'offline_id'     => 'split-offline-1',
            'items'          => [[
                'product_id'   => $context['product']->id,
                'product_name' => 'Powerbank 20000mAh',
                'unit_price'   => 8000,
                'quantity'     => 1,
                'total'        => 8000,
            ]],
            'payment_method' => 'split',
            'total'          => 8000,
            'completed_at'   => now()->toDateTimeString(),
            'payments'       => [
                ['method' => 'cash', 'amount' => 3000],
                ['method' => 'card', 'amount' => 5000],
            ],
        ]],
    ]);

    $response->assertSuccessful();

    $result = collect($response->json('results'))->firstWhere('offline_id', 'split-offline-1');
    expect($result['status'])->toBe('synced');

    $sale = PosSale::where('reference', $result['reference'])->first();
    expect($sale)->not->toBeNull()
        ->and($sale->payment_method)->toBe('split')
        ->and(PosSalePayment::where('pos_sale_id', $sale->id)->count())->toBe(2);
});
