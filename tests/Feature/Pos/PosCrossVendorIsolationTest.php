<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Adversarial: these do not test intended behaviour, they probe whether one
// vendor's till can reach another vendor's books or stock.

function isolationVendor(string $name): array
{
    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name, 'pos_min_margin_percent' => 0]);
    $cat    = Category::create(['name' => 'Cat ' . uniqid()]);

    $product = Product::create([
        'vendor_id'                => $vendor->id,
        'category_id'              => $cat->id,
        'name'                     => $name . ' Widget',
        'price'                    => 10000,
        'cost_price'               => 5000,
        'allow_pos_price_override' => false,
        'stock_quantity'           => 50,
        'reserved_stock'           => 0,
        'status'                   => 'published',
        'show_in_pos'              => true,
    ]);

    return compact('owner', 'vendor', 'product');
}

function isolationSalePayload(int $vendorId, Product $product, int $qty = 1): array
{
    return [
        'vendor_id'      => $vendorId,
        'items'          => [[
            'product_id'   => $product->id,
            'product_name' => $product->name,
            'unit_price'   => (float) $product->price,
            'quantity'     => $qty,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => (float) $product->price * $qty,
        'payments'        => null,
    ];
}

it('does not let a cashier post a sale under a vendor they do not belong to', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');

    // Alpha's owner, authenticated, claims to be selling for Beta.
    Sanctum::actingAs($a['owner']);

    $response = $this->postJson('/api/pos/sales', isolationSalePayload($b["vendor"]->id, $b['product']));

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect(PosSale::where('vendor_id', $b['vendor']->id)->count())->toBe(0);
});

it('does not let a cashier sell another vendor product through their own till', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');

    Sanctum::actingAs($a['owner']);

    // Sale filed under Alpha, but the line item is Beta's product.
    $response = $this->postJson('/api/pos/sales', isolationSalePayload($a["vendor"]->id, $b['product'], 5));

    expect($response->status())->toBeGreaterThanOrEqual(400);

    // Beta's shelf must be untouched.
    expect($b['product']->fresh()->stock_quantity)->toBe(50);
});

it('does not let a cashier void another vendor sale', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');

    // Beta makes a legitimate sale.
    Sanctum::actingAs($b['owner']);
    $this->postJson('/api/pos/sales', isolationSalePayload($b["vendor"]->id, $b['product']))->assertSuccessful();
    $betaSale = PosSale::where('vendor_id', $b['vendor']->id)->firstOrFail();

    // Alpha tries to void it.
    Sanctum::actingAs($a['owner']);
    $response = $this->postJson("/api/pos/sales/{$betaSale->id}/void", ['reason' => 'not mine']);

    expect($response->status())->toBeGreaterThanOrEqual(400);
    expect($betaSale->fresh()->status)->not->toBe('voided');
});

it('does not leak another vendor product catalogue to the till', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');

    Sanctum::actingAs($a['owner']);

    $this->getJson('/api/pos/products?vendor_id=' . $b['vendor']->id)->assertForbidden();
    $this->getJson('/api/pos/products/search?vendor_id=' . $b['vendor']->id . '&q=Beta')->assertForbidden();
});

it('does not leak another vendor sales history or customer list', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');

    Sanctum::actingAs($a['owner']);

    $this->getJson('/api/pos/sales/my-history?vendor_id=' . $b['vendor']->id)->assertForbidden();
    $this->getJson('/api/pos/customers?vendor_id=' . $b['vendor']->id)->assertForbidden();
});

it('still lets a cashier work normally in their own store', function () {
    $a = isolationVendor('Alpha Store');

    Sanctum::actingAs($a['owner']);

    $this->postJson('/api/pos/sales', isolationSalePayload($a["vendor"]->id, $a['product']))->assertSuccessful();
    $this->getJson('/api/pos/products?vendor_id=' . $a['vendor']->id)->assertSuccessful();

    expect($a['product']->fresh()->stock_quantity)->toBe(49);
});

it('lets a member of two vendors work in both, but no third', function () {
    $a = isolationVendor('Alpha Store');
    $b = isolationVendor('Beta Store');
    $c = isolationVendor('Gamma Store');

    // One cashier legitimately employed by Alpha and Beta.
    $cashier = User::factory()->create();
    $a['vendor']->users()->syncWithoutDetaching([$cashier->id]);
    $b['vendor']->users()->syncWithoutDetaching([$cashier->id]);

    Sanctum::actingAs($cashier);

    $this->getJson('/api/pos/products?vendor_id=' . $a['vendor']->id)->assertSuccessful();
    $this->getJson('/api/pos/products?vendor_id=' . $b['vendor']->id)->assertSuccessful();
    $this->getJson('/api/pos/products?vendor_id=' . $c['vendor']->id)->assertForbidden();
});
