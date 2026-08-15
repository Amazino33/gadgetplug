<?php

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function posRevenueVendor(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner    = User::factory()->create();
    $vendor   = Vendor::create(['user_id' => $owner->id, 'name' => 'POS Revenue Store ' . uniqid()]);
    $category = Category::create(['name' => 'POS Revenue Category ' . uniqid()]);
    $product  = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'POS Revenue Product',
        'price'          => 5000,
        'stock_quantity' => 50,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'product');
}

// ─── Plain (non-split) sales ──────────────────────────────────────────

test('a cash sale posts revenue to the cash account', function () {
    $data = posRevenueVendor();
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    Sanctum::actingAs($data['owner']);

    $response = $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 5000,
        'vat_rate'        => 0,
    ]);

    $response->assertSuccessful();
    $sale = PosSale::find($response->json('id'));

    expect($cash->fresh()->balance())->toBe(5000.0)
        ->and(FinancialLedgerEntry::where('source_type', $sale->getMorphClass())
            ->where('source_id', $sale->id)->where('direction', 'in')->count())->toBe(1);
});

test('a card sale posts revenue to the bank account', function () {
    $data = posRevenueVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'card',
        'amount_tendered' => 5000,
        'vat_rate'        => 0,
    ])->assertSuccessful();

    expect($bank->fresh()->balance())->toBe(5000.0);
});

test('a bank transfer sale posts revenue to the bank account', function () {
    $data = posRevenueVendor();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'bank_transfer',
        'amount_tendered' => 5000,
        'vat_rate'        => 0,
    ])->assertSuccessful();

    expect($bank->fresh()->balance())->toBe(5000.0);
});

// ─── Split payments ────────────────────────────────────────────────────

test('a split cash+card sale posts each leg to its own account', function () {
    $data = posRevenueVendor();
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    Sanctum::actingAs($data['owner']);

    $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method' => 'split',
        'payments'       => [
            ['method' => 'cash', 'amount' => 2000],
            ['method' => 'card', 'amount' => 3000],
        ],
        'vat_rate' => 0,
    ])->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(2000.0)
        ->and($bank->fresh()->balance())->toBe(3000.0);
});

test('change on a split sale reduces only the cash leg posted to the ledger', function () {
    $data = posRevenueVendor();
    $data['product']->update(['price' => 4500]);
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    Sanctum::actingAs($data['owner']);

    // Total due is 4500; tendering 3000 cash + 2000 card = 5000, 500 change
    // handed back in cash. The cash account must only keep 2500, not 3000.
    $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 4500,
            'quantity'     => 1,
        ]],
        'payment_method' => 'split',
        'payments'       => [
            ['method' => 'cash', 'amount' => 3000],
            ['method' => 'card', 'amount' => 2000],
        ],
        'vat_rate' => 0,
    ])->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(2500.0)
        ->and($bank->fresh()->balance())->toBe(2000.0)
        ->and($cash->fresh()->balance() + $bank->fresh()->balance())->toBe(4500.0);
});

// ─── Void reversal ─────────────────────────────────────────────────────

test('voiding a cash sale reverses the ledger entry and restores the balance to zero', function () {
    $data = posRevenueVendor();
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    Sanctum::actingAs($data['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 5000,
        'vat_rate'        => 0,
    ])->json();

    expect($cash->fresh()->balance())->toBe(5000.0);

    $this->postJson("/api/pos/sales/{$sale['id']}/void")->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(0.0);
});

test('voiding a split sale reverses both legs', function () {
    $data = posRevenueVendor();
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    $bank = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'bank')->first();
    Sanctum::actingAs($data['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 5000,
            'quantity'     => 1,
        ]],
        'payment_method' => 'split',
        'payments'       => [
            ['method' => 'cash', 'amount' => 2000],
            ['method' => 'card', 'amount' => 3000],
        ],
        'vat_rate' => 0,
    ])->json();

    $this->postJson("/api/pos/sales/{$sale['id']}/void")->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(0.0)
        ->and($bank->fresh()->balance())->toBe(0.0);
});

// ─── Returns ────────────────────────────────────────────────────────────

test('a cash-refunded partial return posts an out entry for the refund amount', function () {
    $data = posRevenueVendor();
    $data['product']->update(['price' => 3000]);
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    Sanctum::actingAs($data['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 3000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 6000,
        'vat_rate'        => 0,
    ])->json();

    expect($cash->fresh()->balance())->toBe(6000.0);

    $this->postJson("/api/pos/sales/{$sale['id']}/return", [
        'items' => [[
            'product_id' => $data['product']->id,
            'quantity'   => 1,
        ]],
        'refund_method' => 'cash',
    ])->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(3000.0);
});

test('a store-credit refund posts nothing to the ledger — no real money left either account', function () {
    $data = posRevenueVendor();
    $data['product']->update(['price' => 3000]);
    $cash = FinancialAccount::where('vendor_id', $data['vendor']->id)->where('type', 'cash')->first();
    Sanctum::actingAs($data['owner']);

    $sale = $this->postJson('/api/pos/sales', [
        'vendor_id' => $data['vendor']->id,
        'items'     => [[
            'product_id'   => $data['product']->id,
            'product_name' => $data['product']->name,
            'unit_price'   => 3000,
            'quantity'     => 2,
        ]],
        'payment_method'  => 'cash',
        'amount_tendered' => 6000,
        'vat_rate'        => 0,
    ])->json();

    $this->postJson("/api/pos/sales/{$sale['id']}/return", [
        'items' => [[
            'product_id' => $data['product']->id,
            'quantity'   => 1,
        ]],
        'refund_method' => 'store_credit',
    ])->assertSuccessful();

    expect($cash->fresh()->balance())->toBe(6000.0);
});
