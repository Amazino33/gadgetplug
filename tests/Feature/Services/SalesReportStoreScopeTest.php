<?php

use App\Models\Category;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function salesScopeVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Scope '.uniqid()]);
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Uyo Branch']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'store_id'       => $vendor->defaultStore->id,
        'category_id'    => Category::firstOrCreate(['name' => 'Scope Cat'])->id,
        'name'           => 'Scoped Widget',
        'sku'            => 'SKU-'.Str::random(6),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 100,
        'status'         => 'published',
    ]);

    return compact('owner', 'vendor', 'branch', 'product');
}

function tillSale(array $ctx, Store $store, float $unitPrice, int $qty): PosSale
{
    $subtotal = $unitPrice * $qty;

    $sale = PosSale::create([
        'reference'       => 'POS-'.Str::random(10),
        'vendor_id'       => $ctx['vendor']->id,
        'store_id'        => $store->id,
        'cashier_id'      => $ctx['owner']->id,
        'subtotal'        => $subtotal,
        'discount_amount' => 0,
        'vat_amount'      => round($subtotal * 0.075, 2),
        'total'           => $subtotal + round($subtotal * 0.075, 2),
        'payment_method'  => 'cash',
        'status'          => 'completed',
        'completed_at'    => CarbonImmutable::now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $ctx['product']->id,
        'product_name' => $ctx['product']->name,
        'unit_price'   => $unitPrice,
        'unit_cost'    => 600,
        'quantity'     => $qty,
        'total'        => $subtotal,
    ]);

    return $sale;
}

function reportRange(): array
{
    return [CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()];
}

test('a branch reports only what it sold', function () {
    $ctx = salesScopeVendor();
    tillSale($ctx, $ctx['vendor']->defaultStore, 1000, 3);
    tillSale($ctx, $ctx['branch'], 1000, 2);

    [$from, $to] = reportRange();
    $reports = app(SalesReportService::class);

    expect($reports->summary($ctx['vendor']->id, $from, $to, $ctx['branch']->id)['revenue'])->toBe(2000.0)
        ->and($reports->summary($ctx['vendor']->id, $from, $to, $ctx['vendor']->defaultStore->id)['revenue'])->toBe(3000.0);
});

test('no branch given still reports the whole vendor, as it always did', function () {
    $ctx = salesScopeVendor();
    tillSale($ctx, $ctx['vendor']->defaultStore, 1000, 3);
    tillSale($ctx, $ctx['branch'], 1000, 2);

    [$from, $to] = reportRange();

    expect(app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to)['revenue'])->toBe(5000.0);
});

test('a refund is netted off the branch that made the sale, not spread across all', function () {
    $ctx = salesScopeVendor();
    $sale = tillSale($ctx, $ctx['branch'], 1000, 2);

    PosReturn::create([
        'reference'        => 'RET-'.Str::random(6),
        'vendor_id'        => $ctx['vendor']->id,
        'original_sale_id' => $sale->id,
        'cashier_id'       => $ctx['owner']->id,
        'return_items'     => [],
        'refund_amount'    => 500,
        'refund_method'    => 'cash',
    ]);

    [$from, $to] = reportRange();
    $reports = app(SalesReportService::class);

    expect($reports->summary($ctx['vendor']->id, $from, $to, $ctx['branch']->id)['revenue'])->toBe(1500.0)
        // The default store never made that sale, so its takings are untouched.
        ->and($reports->summary($ctx['vendor']->id, $from, $to, $ctx['vendor']->defaultStore->id)['revenue'])->toBe(0.0);
});

test('the per-store breakdown lists every branch, biggest first', function () {
    $ctx = salesScopeVendor();
    tillSale($ctx, $ctx['vendor']->defaultStore, 1000, 1);
    tillSale($ctx, $ctx['branch'], 1000, 4);

    [$from, $to] = reportRange();
    $rows = app(SalesReportService::class)->storeBreakdown($ctx['vendor']->id, $from, $to);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['store_name'])->toBe('Uyo Branch')
        ->and($rows[0]['revenue'])->toBe(4000.0)
        ->and($rows[1]['revenue'])->toBe(1000.0)
        // Every naira is accounted for, so no phantom unattributed row.
        ->and($rows->sum('revenue'))->toBe(5000.0);
});

test('the cashier and top-product tables narrow to the branch too', function () {
    $ctx = salesScopeVendor();
    tillSale($ctx, $ctx['vendor']->defaultStore, 1000, 3);
    tillSale($ctx, $ctx['branch'], 1000, 2);

    [$from, $to] = reportRange();
    $reports = app(SalesReportService::class);

    expect($reports->cashierBreakdown($ctx['vendor']->id, $from, $to, $ctx['branch']->id)->first()['revenue'])->toBe(2000.0)
        ->and($reports->topProducts($ctx['vendor']->id, $from, $to, 10, $ctx['branch']->id)->first()['units'])->toBe(2);
});
