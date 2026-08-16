<?php

use App\Actions\Inventory\DispatchStockAction;
use App\Actions\Inventory\ReserveStockAction;
use App\Models\BlindCountSession;
use App\Models\Order;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Services\Reporting\Cards\AdEfficiencyCardProvider;
use App\Services\Reporting\Cards\MoneyPositionCardProvider;
use App\Services\Reporting\Cards\SalesPulseCardProvider;
use App\Services\Reporting\StoreSalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Its own fixtures, under their own names: Pest loads every file in a run
// into one namespace, so reusing the allocation file's helper names would
// redeclare them, and borrowing them would make this file pass together and
// fail alone.
function salesVendor(): App\Models\Vendor
{
    return App\Models\Vendor::create([
        'user_id' => App\Models\User::factory()->create()->id,
        'name'    => 'Sales Vendor '.uniqid(),
    ]);
}

function salesProduct(App\Models\Vendor $vendor, int $defaultQty, array $branches = []): Product
{
    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => App\Models\Category::create(['name' => 'Cat '.uniqid()])->id,
        'name'           => 'Sales Product '.uniqid(),
        'price'          => 1000,
        'cost_price'     => 600,
        'stock_quantity' => 0,
        'status'         => 'published',
    ]);

    ProductStoreStock::where('product_id', $product->id)->first()->update(['quantity' => $defaultQty]);

    foreach ($branches as $name => $qty) {
        $store = Store::create(['vendor_id' => $vendor->id, 'name' => $name]);
        ProductStoreStock::create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => $qty]);
    }

    return $product->fresh();
}

function salesOrderItem(Product $product, int $quantity): App\Models\OrderItem
{
    $order = Order::create([
        'reference' => 'ORD-'.strtoupper(uniqid()),
        'customer_name' => 'Buyer', 'customer_email' => 'b@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo',
        'total_amount' => 1000 * $quantity, 'status' => 'pending', 'payment_method' => 'pay_on_delivery',
    ]);

    return App\Models\OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id,
        'vendor_id' => $product->vendor_id, 'quantity' => $quantity, 'unit_price' => 1000,
    ]);
}

function recogniseOrder(Order $order): void
{
    // The revenue definition every report shares — marked recognised, not
    // merely placed.
    $order->forceFill(['revenue_recognized_at' => now()])->saveQuietly();
}

// ─── Per-store sales: online allocations ────────────────────────────

test('a split order credits each branch with the units it supplied', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 5, ['Branch B' => 3]);
    $default = $vendor->defaultStore->id;
    $branch = Store::where('name', 'Branch B')->value('id');

    $item = salesOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    recogniseOrder($item->order);

    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    $atDefault = StoreSalesQuery::totals($vendor->id, $default, $from, $to);
    $atBranch = StoreSalesQuery::totals($vendor->id, $branch, $from, $to);
    $vendorWide = StoreSalesQuery::totals($vendor->id, null, $from, $to);

    expect($atDefault['units'])->toBe(5)
        ->and($atDefault['revenue'])->toBe(5000.0)
        ->and($atBranch['units'])->toBe(1)
        ->and($atBranch['revenue'])->toBe(1000.0)
        // The two branches sum to the whole line, never double it.
        ->and($vendorWide['units'])->toBe(6)
        ->and($vendorWide['revenue'])->toBe(6000.0);
});

test('an unrecognised order counts for no store', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 5);
    $item = salesOrderItem($product, 2);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 2, orderItemId: $item->id);

    $totals = StoreSalesQuery::totals($vendor->id, $vendor->defaultStore->id,
        CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());

    expect($totals['units'])->toBe(0)->and($totals['revenue'])->toBe(0.0);
});

// ─── Per-store sales: POS must be in the union ──────────────────────

test('counter sales count toward their branch, not just online orders', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 20, ['Branch B' => 20]);
    $branch = Store::where('name', 'Branch B')->value('id');

    $sale = PosSale::create([
        'reference' => 'POS-'.strtoupper(uniqid()),
        'vendor_id' => $vendor->id,
        'store_id'  => $branch,
        'cashier_id' => $vendor->user_id,
        'subtotal' => 4000, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => 4000,
        'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => now(),
    ]);
    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'unit_price' => 1000, 'quantity' => 4, 'total' => 4000,
    ]);

    $from = CarbonImmutable::now()->subDay();
    $to = CarbonImmutable::now()->addDay();

    expect(StoreSalesQuery::totals($vendor->id, $branch, $from, $to))
        ->units->toBe(4)
        ->revenue->toBe(4000.0)
        // The other branch sold nothing.
        ->and(StoreSalesQuery::totals($vendor->id, $vendor->defaultStore->id, $from, $to)['revenue'])->toBe(0.0);
});

test('a voided counter sale is excluded', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 20);

    $sale = PosSale::create([
        'reference' => 'POS-'.strtoupper(uniqid()),
        'vendor_id' => $vendor->id, 'store_id' => $vendor->defaultStore->id,
        'cashier_id' => $vendor->user_id,
        'subtotal' => 1000, 'discount_amount' => 0, 'vat_amount' => 0, 'total' => 1000,
        'payment_method' => 'cash', 'status' => 'voided', 'completed_at' => now(),
    ]);
    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    expect(StoreSalesQuery::totals($vendor->id, $vendor->defaultStore->id,
        CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay())['revenue'])->toBe(0.0);
});

// ─── Blind count is scoped to the counted branch ────────────────────

test('a store-scoped count trues up only that branch and leaves the other alone', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 10, ['Branch B' => 7]);
    $branch = Store::where('name', 'Branch B')->value('id');
    $default = $vendor->defaultStore->id;

    // The branch is counted and found to hold 5, not 7.
    $session = BlindCountSession::create([
        'vendor_id' => $vendor->id,
        'store_id'  => $branch,
        'storekeeper_a_id' => $vendor->user_id,
        'status' => 'a_counting',
        'frequency' => 'daily',
        'by_category' => false,
        'product_order' => [$product->id],
    ]);

    app(App\Actions\Inventory\AdjustStockAction::class)->execute(
        productId: $product->id,
        quantityChanged: 5 - 7,
        transactionType: 'audit_correction',
        reference: "Inventory Count #{$session->id}",
        store: $session->store_id,
    );

    $rows = ProductStoreStock::where('product_id', $product->id)->get()->keyBy('store_id');

    expect($rows[$branch]->quantity)->toBe(5)
        // Untouched: the other branch was not counted.
        ->and($rows[$default]->quantity)->toBe(10)
        ->and($product->fresh()->stock_quantity)->toBe(15);
});

test('the count product set is limited to what the branch carries', function () {
    $vendor = salesVendor();
    $atBoth = salesProduct($vendor, 4, ['Branch B' => 2]);
    $defaultOnly = salesProduct($vendor, 9);
    $branch = Store::where('name', 'Branch B')->value('id');

    $carried = Product::published()
        ->where('vendor_id', $vendor->id)
        ->whereHas('storeStocks', fn ($s) => $s->where('store_id', $branch))
        ->pluck('id');

    expect($carried->all())->toBe([$atBoth->id])
        ->and($carried)->not->toContain($defaultOnly->id);
});

// ─── Reports hub ────────────────────────────────────────────────────

test('sales pulse reports per store and vendor-wide', function () {
    $vendor = salesVendor();
    $product = salesProduct($vendor, 5, ['Branch B' => 3]);
    $branch = Store::where('name', 'Branch B')->value('id');

    $item = salesOrderItem($product, 6);
    app(ReserveStockAction::class)->execute(productId: $product->id, quantity: 6, orderItemId: $item->id);
    recogniseOrder($item->order);

    $wide = app(SalesPulseCardProvider::class)->summarize($vendor->id);
    $atBranch = app(SalesPulseCardProvider::class)->summarize($vendor->id, $branch);

    expect($wide->headline)->toContain('6,000.00')
        ->and($atBranch->headline)->toContain('1,000.00')
        ->and($wide->vendorWideOnly)->toBeFalse()
        ->and($atBranch->vendorWideOnly)->toBeFalse();
});

test('money and ad cards stay vendor-wide and say so under a store filter', function () {
    $vendor = salesVendor();
    salesProduct($vendor, 5);
    $store = $vendor->defaultStore->id;

    foreach ([MoneyPositionCardProvider::class, AdEfficiencyCardProvider::class] as $provider) {
        $wide = app($provider)->summarize($vendor->id);
        $filtered = app($provider)->summarize($vendor->id, $store);

        expect($wide->vendorWideOnly)->toBeFalse()
            ->and($wide->scopeLabel())->toBeNull()
            // Same number either way — the filter cannot honestly change it.
            ->and($filtered->headline)->toBe($wide->headline)
            // But the card now declares its scope rather than implying the store's.
            ->and($filtered->vendorWideOnly)->toBeTrue()
            ->and($filtered->scopeLabel())->toBe('Whole business — not this store');
    }
});

test('passing no store leaves every provider exactly as it was', function () {
    $vendor = salesVendor();
    salesProduct($vendor, 5);

    foreach ([SalesPulseCardProvider::class, MoneyPositionCardProvider::class, AdEfficiencyCardProvider::class] as $provider) {
        $card = app($provider)->summarize($vendor->id);

        expect($card->vendorWideOnly)->toBeFalse()
            ->and($card->headline)->toBeString();
    }
});

test('restock and dead stock read the named branch shelf', function () {
    $vendor = salesVendor();
    // Empty at the branch, well stocked at the default.
    $product = salesProduct($vendor, 100, ['Branch B' => 0]);
    $branch = Store::where('name', 'Branch B')->value('id');

    $wide = app(App\Services\Reporting\ProductVelocityService::class)
        ->forVendor(vendorId: $vendor->id);
    $atBranch = app(App\Services\Reporting\ProductVelocityService::class)
        ->forVendor(vendorId: $vendor->id, storeId: $branch);

    expect($wide->firstWhere('productId', $product->id)->currentStock)->toBe(100)
        // Same product, same moment — the branch's shelf is empty, so restock
        // and dead-stock read it as needing attention while the vendor-wide
        // view sees a hundred units.
        ->and($atBranch->firstWhere('productId', $product->id)->currentStock)->toBe(0);
});
