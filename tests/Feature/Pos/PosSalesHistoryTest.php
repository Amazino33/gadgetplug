<?php

use App\Models\Category;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\Sanctum;

// A storekeeper should see only their own sales, not every till's activity.

function historyContext(): array
{
    $vendorOwner = User::factory()->create();
    $vendor      = Vendor::create(['user_id' => $vendorOwner->id, 'name' => 'History Test Store']);
    $category    = Category::create(['name' => 'History Category']);
    $product     = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'History Product',
        'price'          => 4000,
        'stock_quantity' => 20,
        'status'         => 'published',
    ]);

    $cashierA = User::factory()->create();
    $cashierB = User::factory()->create();

    // Both cashiers actually work here. Without this they are strangers to the
    // vendor, and EnsurePosVendorAccess correctly refuses them the store's
    // history — the endpoint is not "any authenticated user may read this
    // vendor's sales", it never was meant to be.
    $vendor->users()->syncWithoutDetaching([$cashierA->id, $cashierB->id]);

    return compact('vendor', 'vendorOwner', 'product', 'cashierA', 'cashierB');
}

function makeHistorySale(Vendor $vendor, User $cashier, Product $product, string $reference): PosSale
{
    $sale = PosSale::create([
        'reference'       => $reference,
        'vendor_id'       => $vendor->id,
        'cashier_id'      => $cashier->id,
        'subtotal'        => 4000,
        'vat_amount'      => 0,
        'total'           => 4000,
        'payment_method'  => 'cash',
        'amount_tendered' => 4000,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $product->id,
        'product_name' => $product->name,
        'unit_price'   => 4000,
        'quantity'     => 1,
        'total'        => 4000,
    ]);

    return $sale;
}

it('only returns sales rung up by the authenticated cashier', function () {
    $context = historyContext();
    makeHistorySale($context['vendor'], $context['cashierA'], $context['product'], 'POS-MINE');
    makeHistorySale($context['vendor'], $context['cashierB'], $context['product'], 'POS-NOT-MINE');

    Sanctum::actingAs($context['cashierA']);

    $response = $this->getJson('/api/pos/sales/my-history?vendor_id=' . $context['vendor']->id);

    $response->assertSuccessful();

    $references = collect($response->json('data'))->pluck('reference');

    expect($references)->toContain('POS-MINE')
        ->and($references)->not->toContain('POS-NOT-MINE');
});

it('includes items on each sale so a receipt can be reprinted', function () {
    $context = historyContext();
    makeHistorySale($context['vendor'], $context['cashierA'], $context['product'], 'POS-WITH-ITEMS');

    Sanctum::actingAs($context['cashierA']);

    $response = $this->getJson('/api/pos/sales/my-history?vendor_id=' . $context['vendor']->id);

    $sale = collect($response->json('data'))->firstWhere('reference', 'POS-WITH-ITEMS');

    expect($sale['items'])->toHaveCount(1)
        ->and($sale['items'][0]['product_name'])->toBe('History Product');
});

it('does not leak sales from a different vendor', function () {
    $context  = historyContext();
    $otherVendorOwner = User::factory()->create();
    $otherVendor = Vendor::create(['user_id' => $otherVendorOwner->id, 'name' => 'Other Store']);

    makeHistorySale($context['vendor'], $context['cashierA'], $context['product'], 'POS-VENDOR-A');
    makeHistorySale($otherVendor, $context['cashierA'], $context['product'], 'POS-VENDOR-B');

    Sanctum::actingAs($context['cashierA']);

    $response = $this->getJson('/api/pos/sales/my-history?vendor_id=' . $context['vendor']->id);

    $references = collect($response->json('data'))->pluck('reference');

    expect($references)->toContain('POS-VENDOR-A')
        ->and($references)->not->toContain('POS-VENDOR-B');
});
