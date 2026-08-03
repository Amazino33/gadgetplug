<?php

use App\Models\Category;
use App\Models\InventoryLedger;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

// The 'pos_void' and 'pos_return' transaction types were never added to the
// inventory_ledgers.transaction_type enum, so voiding a sale or processing a
// return threw a DB error on MySQL (strict mode) and rolled back the whole
// action. These confirm both now actually complete.

function voidReturnContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'Void Return Store']);
    $category = Category::create(['name' => 'Void Return Category']);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'Void Return Product',
        'price'          => 3000,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

it('voids a completed sale and records a pos_void ledger entry without erroring', function () {
    $context = voidReturnContext();
    Sanctum::actingAs($context['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $context['vendor']->id,
        'items'     => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'Void Return Product',
            'unit_price'   => 3000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 6000,
        'vat_rate'        => 0,
    ])->json();

    $response = $this->postJson("/api/pos/sales/{$sale['id']}/void");

    $response->assertSuccessful();

    expect(PosSale::find($sale['id'])->status)->toBe('voided')
        ->and(InventoryLedger::where('product_id', $context['product']->id)->where('transaction_type', 'pos_void')->exists())->toBeTrue()
        ->and($context['product']->fresh()->stock_quantity)->toBe(10);
});

it('processes a return and records a pos_return ledger entry without erroring', function () {
    $context = voidReturnContext();
    Sanctum::actingAs($context['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $context['vendor']->id,
        'items'     => [[
            'product_id'   => $context['product']->id,
            'product_name' => 'Void Return Product',
            'unit_price'   => 3000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 6000,
        'vat_rate'        => 0,
    ])->json();

    $response = $this->postJson("/api/pos/sales/{$sale['id']}/return", [
        'items' => [[
            'product_id' => $context['product']->id,
            'quantity'   => 1,
        ]],
        'refund_method' => 'cash',
    ]);

    $response->assertSuccessful();

    expect(PosSale::find($sale['id'])->status)->toBe('partial_refund')
        ->and(InventoryLedger::where('product_id', $context['product']->id)->where('transaction_type', 'pos_return')->exists())->toBeTrue()
        ->and($context['product']->fresh()->stock_quantity)->toBe(9);
});
