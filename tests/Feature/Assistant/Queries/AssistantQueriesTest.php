<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\AffiliateCommissionsQuery;
use App\Actions\Assistant\Queries\CashierShortageQuery;
use App\Actions\Assistant\Queries\CashVarianceQuery;
use App\Actions\Assistant\Queries\CustomerDebtsQuery;
use App\Actions\Assistant\Queries\CustomerHistoryQuery;
use App\Actions\Assistant\Queries\DeadStockQuery;
use App\Actions\Assistant\Queries\ExpenseSummaryQuery;
use App\Actions\Assistant\Queries\GlossaryQuery;
use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\OutOfStockQuery;
use App\Actions\Assistant\Queries\PickerItemsOutQuery;
use App\Actions\Assistant\Queries\ProfitQuery;
use App\Actions\Assistant\Queries\QueryResult;
use App\Actions\Assistant\Queries\RevenueVsPreviousQuery;
use App\Actions\Assistant\Queries\SalesByDayQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\SlowProductsQuery;
use App\Actions\Assistant\Queries\StockValueQuery;
use App\Actions\Assistant\Queries\SupplierOweingsQuery;
use App\Actions\Assistant\Queries\TopProductsQuery;
use App\Actions\Assistant\Queries\VendorPayoutsDueQuery;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemStoreAllocation;
use App\Models\Picker;
use App\Models\Picking;
use App\Models\PickingItem;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSession;
use App\Models\PosZReport;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TillExpense;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Services\Assistant\AssistantQueryRegistry;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function assistantQueriesVendor(): array
{
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Test Vendor '.uniqid()]);
    $store = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Main Branch',
        'created_at' => now()->subDays(60),
    ]);

    test()->actingAs($user);

    return [$user, $vendor, $store];
}

function assistantQueriesPosSale(Vendor $vendor, Store $store, User $cashier, float $amount, ?CarbonInterface $completedAt = null): PosSale
{
    return PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $cashier->id,
        'subtotal' => $amount,
        'discount_amount' => 0,
        'total' => $amount,
        'payment_method' => 'cash',
        'status' => 'completed',
        'completed_at' => $completedAt ?? now()->subDays(1),
    ]);
}

function makeProduct(Vendor $vendor, string $name, float $cost = 0.0): Product
{
    $category = Category::firstOrCreate(['name' => 'General', 'slug' => 'general']);

    return Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => $name,
        'price' => 100,
        'cost_price' => $cost,
        'status' => 'published',
    ]);
}

test('SalesSummaryQuery handles empty data and returns exact sum on aggregate stores', function () {
    [$user, $vendor, $store1] = assistantQueriesVendor();
    $store2 = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Second Branch',
        'created_at' => now()->subDays(60),
    ]);

    $query = new SalesSummaryQuery;
    $from = now()->subDays(30);
    $to = now();

    // 1. Empty data
    $empty = $query->execute(['store_id' => $store1->id], $from, $to);
    expect($empty['revenue'])->toBe(0.0)
        ->and($empty['orders'])->toBe(0)
        ->and($empty['has_data'])->toBeTrue()
        ->and($empty['reason'])->toBeNull()
        ->and(is_string($empty['revenue_display']))->toBeTrue()
        ->and(is_int($empty['orders']))->toBeTrue();

    // 2. Sales in store 1 (₦150.00)
    assistantQueriesPosSale($vendor, $store1, $user, 150.00, now()->subDays(2));

    // 3. Sales in store 2 (₦250.00)
    assistantQueriesPosSale($vendor, $store2, $user, 250.00, now()->subDays(3));

    // Single store query
    $res1 = $query->execute(['store_id' => $store1->id], $from, $to);
    expect($res1['revenue'])->toBe(150.0)
        ->and($res1['orders'])->toBe(1);

    // Multi-store aggregate math
    $agg = $query->execute(['store_ids' => [$store1->id, $store2->id]], $from, $to);
    expect($agg['revenue'])->toBe(400.0)
        ->and($agg['orders'])->toBe(2)
        ->and(is_string($agg['revenue_display']))->toBeTrue();
});

test('SalesByDayQuery returns structured daily breakdown in naira with display strings', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $query = new SalesByDayQuery;
    $from = now()->subDays(5);
    $to = now();

    // Empty
    $empty = $query->execute(['store_id' => $store->id], $from, $to);
    expect($empty['days'])->toBeArray()->and($empty['days'])->toBeEmpty();

    // Add sale
    assistantQueriesPosSale($vendor, $store, $user, 500.00, now()->subDays(1));

    $res = $query->execute(['store_id' => $store->id], $from, $to);
    expect($res['days'])->toHaveCount(1)
        ->and($res['days'][0])->toHaveKeys(['date', 'revenue', 'orders'])
        ->and($res['days'][0]['revenue'])->toBe(500.0)
        ->and(is_string($res['days'][0]['revenue_display']))->toBeTrue();
});

test('TopProductsQuery returns top 5 products sorted by revenue desc', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $query = new TopProductsQuery;
    $from = now()->subDays(30);
    $to = now();

    // Empty (no sales ever)
    $empty = $query->execute(['store_id' => $store->id], $from, $to);
    expect($empty['has_data'])->toBeFalse()
        ->and($empty['reason'])->toBe('no_sales_yet');

    $p1 = makeProduct($vendor, 'Gadget A');
    $p2 = makeProduct($vendor, 'Gadget B');

    $sale = assistantQueriesPosSale($vendor, $store, $user, 300.00, now()->subDays(2));

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $p1->id,
        'product_name' => $p1->name,
        'quantity' => 1,
        'unit_price' => 200,
        'total' => 200,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $p2->id,
        'product_name' => $p2->name,
        'quantity' => 1,
        'unit_price' => 100,
        'total' => 100,
    ]);

    $res = $query->execute(['store_id' => $store->id], $from, $to);
    expect($res['products'])->toHaveCount(2)
        ->and($res['products'][0]['product_id'])->toBe($p1->id)
        ->and($res['products'][0]['revenue'])->toBe(200.0)
        ->and(is_string($res['products'][0]['revenue_display']))->toBeTrue()
        ->and($res['products'][1]['product_id'])->toBe($p2->id);
});

test('SlowProductsQuery returns store_too_new for new stores and bottom products for older stores', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Shop '.uniqid()]);
    $newStore = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Brand New Store',
        'created_at' => now()->subDays(3),
    ]);
    test()->actingAs($user);

    $query = new SlowProductsQuery;

    // 1. Store < 7 days old
    $resNew = $query->execute(['store_id' => $newStore->id], now()->subDays(7), now());
    expect($resNew['has_data'])->toBeFalse()
        ->and($resNew['reason'])->toBe('store_too_new');

    // 2. Store >= 7 days old
    $oldStore = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Established Store',
        'created_at' => now()->subDays(15),
    ]);

    $p1 = makeProduct($vendor, 'Slow Mouse');
    ProductStoreStock::create(['store_id' => $oldStore->id, 'product_id' => $p1->id, 'quantity' => 10, 'reserved' => 0]);

    $resOld = $query->execute(['store_id' => $oldStore->id], now()->subDays(7), now());
    expect($resOld['has_data'])->toBeTrue()
        ->and($resOld['reason'])->toBeNull()
        ->and($resOld['products'])->toHaveCount(1)
        ->and($resOld['products'][0]['units'])->toBe(0);
});

test('RevenueVsPreviousQuery calculates period revenue and percentage change', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $query = new RevenueVsPreviousQuery;

    $to = now();
    $from = now()->subDays(10); // 10 day window

    // Current period sale: ₦200 = 20000 kobo
    assistantQueriesPosSale($vendor, $store, $user, 200.00, now()->subDays(2));

    // Previous period sale: ₦100 = 10000 kobo
    assistantQueriesPosSale($vendor, $store, $user, 100.00, now()->subDays(15));

    $res = $query->execute(['store_id' => $store->id], $from, $to);

    expect($res['current_revenue'])->toBe(200.0)
        ->and($res['previous_revenue'])->toBe(100.0)
        ->and($res['percentage_change'])->toBe(100.0)
        ->and(is_string($res['current_revenue_display']))->toBeTrue()
        ->and(is_string($res['previous_revenue_display']))->toBeTrue();
});

test('LowStockQuery and OutOfStockQuery return correct items and respect threshold', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $pLow = makeProduct($vendor, 'Low Cable');
    $pOut = makeProduct($vendor, 'Out Cable');
    $pHigh = makeProduct($vendor, 'Plenty Cable');

    // One home store per product, as the Products screen and the till read them.
    foreach ([$pLow, $pOut, $pHigh] as $p) {
        $p->update(['store_id' => $store->id]);
    }

    ProductStoreStock::updateOrCreate(['store_id' => $store->id, 'product_id' => $pLow->id], ['quantity' => 3, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['store_id' => $store->id, 'product_id' => $pOut->id], ['quantity' => 0, 'reserved' => 0]);
    ProductStoreStock::updateOrCreate(['store_id' => $store->id, 'product_id' => $pHigh->id], ['quantity' => 25, 'reserved' => 0]);

    // Low stock test (default threshold 5)
    $lowQuery = new LowStockQuery;
    $lowRes = $lowQuery->execute(['store_id' => $store->id], now()->subDays(1), now(), ['threshold' => 5]);

    expect($lowRes['threshold'])->toBe(5)
        ->and($lowRes['items'])->toHaveCount(1)
        ->and($lowRes['items'][0]['name'])->toBe('Low Cable')
        ->and($lowRes['items'][0]['available'])->toBe(3);

    // Out of stock test
    $outQuery = new OutOfStockQuery;
    $outRes = $outQuery->execute(['store_id' => $store->id], now()->subDays(1), now());

    expect($outRes['items'])->toHaveCount(1)
        ->and($outRes['items'][0]['name'])->toBe('Out Cable')
        ->and($outRes['items'][0]['available'])->toBe(0);
});

test('DeadStockQuery returns store_too_new for stores < 30 days old and flags dead inventory', function () {
    $user = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $user->id, 'name' => 'Shop '.uniqid()]);
    $newStore = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'New Store',
        'created_at' => now()->subDays(10),
    ]);
    test()->actingAs($user);

    $query = new DeadStockQuery;
    $resNew = $query->execute(['store_id' => $newStore->id], now()->subDays(30), now());
    expect($resNew['has_data'])->toBeFalse()
        ->and($resNew['reason'])->toBe('store_too_new');

    // Established store (40 days old)
    $oldStore = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Old Store',
        'created_at' => now()->subDays(40),
    ]);

    $pDead = makeProduct($vendor, 'Unsold Gadget');
    ProductStoreStock::create(['store_id' => $oldStore->id, 'product_id' => $pDead->id, 'quantity' => 8, 'reserved' => 0]);

    $resOld = $query->execute(['store_id' => $oldStore->id], now()->subDays(30), now());
    expect($resOld['has_data'])->toBeTrue()
        ->and($resOld['reason'])->toBeNull()
        ->and($resOld['items'])->toHaveCount(1)
        ->and($resOld['items'][0]['product_id'])->toBe($pDead->id);
});

test('StockValueQuery computes total inventory cost in naira with display strings', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $query = new StockValueQuery;

    // Empty
    $empty = $query->execute(['store_id' => $store->id], now()->subDays(1), now());
    expect($empty['total_cost_value'])->toBe(0.0)
        ->and($empty['total_units'])->toBe(0)
        ->and($empty['has_data'])->toBeTrue()
        ->and($empty['reason'])->toBeNull()
        ->and(is_string($empty['total_cost_value_display']))->toBeTrue();

    $p = makeProduct($vendor, 'Valuable Item', 100.00);
    ProductStoreStock::create(['store_id' => $store->id, 'product_id' => $p->id, 'quantity' => 10, 'reserved' => 0]);

    StockCostLayer::create([
        'product_id' => $p->id,
        'store_id' => $store->id,
        'quantity_received' => 10,
        'quantity_remaining' => 10,
        'unit_cost' => 100.00,
        'received_at' => now()->subDays(5),
    ]);

    $res = $query->execute(['store_id' => $store->id], now()->subDays(1), now());
    // 10 units * ₦100.00 = ₦1,000.00 = 100,000 kobo
    expect($res['total_cost_value'])->toBe(1000.0)
        ->and($res['total_units'])->toBe(10)
        ->and(is_string($res['total_cost_value_display']))->toBeTrue();
});

test('CashVarianceQuery returns expected and counted variance in naira with display strings', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $session = PosSession::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $user->id,
        'business_date' => now()->toDateString(),
        'status' => 'closed',
        'opened_at' => now()->subDay(),
        'opening_float' => 1000.00,
    ]);

    PosZReport::create([
        'vendor_id' => $vendor->id,
        'pos_session_id' => $session->id,
        'cashier_id' => $user->id,
        'report_date' => now()->toDateString(),
        'cash_expected' => 1500.00,
        'cash_counted' => 1480.00,
        'cash_variance' => -20.00,
        'generated_at' => now()->subDays(1),
        'created_at' => now()->subDays(1),
    ]);

    $query = new CashVarianceQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(5), now());

    expect($res['cash_ups'])->toHaveCount(1)
        ->and($res['total_expected'])->toBe(1500.0)
        ->and($res['total_counted'])->toBe(1480.0)
        ->and($res['total_variance'])->toBe(-20.0)
        ->and(is_string($res['total_variance_display']))->toBeTrue();
});

test('CustomerDebtsQuery calculates outstanding balances and oldest debt date', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $customer = PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Debtor John']);

    PosCustomerLedgerEntry::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'pos_customer_id' => $customer->id,
        'direction' => PosCustomerLedgerEntry::DIRECTION_CHARGE,
        'amount' => 500.00,
        'occurred_at' => '2026-10-01',
    ]);

    $query = new CustomerDebtsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(30), now());

    expect($res['total_owed'])->toBe(500.0)
        ->and($res['debtor_count'])->toBe(1)
        ->and($res['oldest_debt_date'])->toBe('2026-10-01')
        ->and(is_string($res['total_owed_display']))->toBeTrue()
        ->and($res['debtors'])->toBeArray()
        ->and($res['debtors'][0]['name'])->toBe('Debtor John')
        ->and($res['debtors'][0]['amount'])->toBe(500.0);
});

test('CustomerDebtsQuery seeds debt for Emeka Eze and asserts debtor name', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $customer = PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Emeka Eze']);

    PosCustomerLedgerEntry::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'pos_customer_id' => $customer->id,
        'direction' => PosCustomerLedgerEntry::DIRECTION_CHARGE,
        'amount' => 45000.00,
        'occurred_at' => '2026-10-01',
    ]);

    $query = new CustomerDebtsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(30), now());

    expect($res['debtors'])->toBeArray()
        ->and($res['debtors'][0]['name'])->toBe('Emeka Eze')
        ->and($res['debtors'][0]['amount'])->toBe(45000.0);
});

test('SupplierOweingsQuery returns unpaid procurements and schedule dates in naira with display strings', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Supplier Co']);

    $proc = Procurement::create([
        'reference' => 'PROC-'.Str::random(8),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'created_by' => $user->id,
        'recorded_by' => $user->id,
        'total_cost' => 1000.00,
        'amount_paid' => 400.00,
        'payment_status' => 'part_payment',
        'status' => Procurement::STATUS_APPROVED,
    ]);
    $proc->created_at = now()->subDays(2);
    $proc->saveQuietly();

    $query = new SupplierOweingsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(10), now());

    expect($res['total_unpaid'])->toBe(600.0)
        ->and($res['invoices_count'])->toBe(1)
        ->and($res['due_dates'])->toHaveCount(1)
        ->and(is_string($res['total_unpaid_display']))->toBeTrue();
});

test('VendorPayoutsDueQuery returns vendor-scoped payout numbers', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $p1 = VendorPayout::create([
        'vendor_id' => $vendor->id,
        'amount' => 200.00,
        'bank_name' => 'First Bank',
        'account_number' => '1234567890',
        'account_name' => 'Test Vendor',
        'status' => 'pending',
    ]);
    $p1->created_at = now()->subDays(2);
    $p1->saveQuietly();

    $p2 = VendorPayout::create([
        'vendor_id' => $vendor->id,
        'amount' => 300.00,
        'bank_name' => 'First Bank',
        'account_number' => '1234567890',
        'account_name' => 'Test Vendor',
        'status' => 'paid',
    ]);
    $p2->created_at = now()->subDays(2);
    $p2->saveQuietly();

    $query = new VendorPayoutsDueQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(10), now());

    expect($res['pending_total'])->toBe(200.0)
        ->and($res['paid_total'])->toBe(300.0)
        ->and(is_string($res['pending_total_display']))->toBeTrue()
        ->and(is_string($res['paid_total_display']))->toBeTrue();
});

test('AffiliateCommissionsQuery aggregates commission amounts in naira', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $affiliate = Affiliate::create(['user_id' => $user->id, 'code' => 'AFF'.uniqid()]);
    $order = Order::create([
        'reference' => 'GP-ORD-'.uniqid(),
        'customer_name' => 'Test Buyer',
        'customer_email' => 'buyer@test.com',
        'customer_phone' => '08000000000',
        'shipping_address' => 'Lagos Nigeria',
        'payment_method' => 'card',
        'total_amount' => 100,
        'status' => 'paid',
    ]);

    $p = makeProduct($vendor, 'Affiliate Item');

    $item = OrderItem::create([
        'order_id' => $order->id,
        'vendor_id' => $vendor->id,
        'product_id' => $p->id,
        'product_name' => 'Demo',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    OrderItemStoreAllocation::create([
        'order_item_id' => $item->id,
        'store_id' => $store->id,
        'quantity' => 1,
    ]);

    $comm = AffiliateCommission::create([
        'affiliate_id' => $affiliate->id,
        'order_id' => $order->id,
        'amount' => 50.00,
        'status' => 'pending',
    ]);
    $comm->created_at = now()->subDays(1);
    $comm->saveQuietly();

    $query = new AffiliateCommissionsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(5), now());

    expect($res['earned_total'])->toBe(50.0)
        ->and($res['pending_total'])->toBe(50.0)
        ->and($res['paid_total'])->toBe(0.0)
        ->and(is_string($res['earned_total_display']))->toBeTrue();
});

test('PickerItemsOutQuery returns products out with pickers', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $picker = Picker::create(['vendor_id' => $vendor->id, 'name' => 'Rider Mike']);
    $product = makeProduct($vendor, 'Dispatched Phone');

    $picking = Picking::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'picker_id' => $picker->id,
        'released_by' => $user->id,
        'taken_at' => now()->subDay(),
    ]);

    PickingItem::create([
        'picking_id' => $picking->id,
        'product_id' => $product->id,
        'quantity' => 4,
        'unit_cost' => 500,
    ]);

    $query = new PickerItemsOutQuery;
    $res = $query->execute(['store_id' => $store->id], now()->subDays(5), now());

    expect($res['total_held_units'])->toBe(4)
        ->and($res['items'])->toHaveCount(1)
        ->and($res['items'][0]['product_id'])->toBe($product->id)
        ->and($res['items'][0]['quantity'])->toBe(4);
});

test('GlossaryQuery returns definition for known terms and found=false for unknown terms', function () {
    $query = new GlossaryQuery;

    // Known term (case-insensitive)
    $resKnown = $query->execute([], now(), now(), ['term' => 'Blind Count']);
    expect($resKnown['found'])->toBeTrue()
        ->and($resKnown['definition'])->toContain('physical stock count');

    // Cost layer
    $resCost = $query->execute([], now(), now(), ['term' => 'cost layer']);
    expect($resCost['found'])->toBeTrue();

    // Unknown term
    $resUnknown = $query->execute([], now(), now(), ['term' => 'quantum blockchain']);
    expect($resUnknown['found'])->toBeFalse()
        ->and($resUnknown['definition'])->toBe('');
});

test('every query returns complete structured keys on empty data (table-driven loop across all 22)', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $registry = new AssistantQueryRegistry;

    expect(count($registry->keys()))->toBe(22);

    foreach ($registry->keys() as $key) {
        $queryInstance = $registry->get($key);
        $scope = ($key === 'glossary') ? [] : ['store_id' => $store->id];
        $options = ($key === 'glossary') ? ['term' => 'nonexistent'] : [];

        $result = $queryInstance->execute($scope, now()->subDays(7), now(), $options);

        expect($result)->toBeArray()
            ->and(array_key_exists('has_data', $result))->toBeTrue("Key [has_data] missing for query [{$key}].")
            ->and(array_key_exists('reason', $result))->toBeTrue("Key [reason] missing for query [{$key}].");

        if ($result['has_data'] === true) {
            expect($result['reason'])->toBeNull("Query [{$key}] has_data is true but reason is not null.");
        } else {
            expect(in_array($result['reason'], QueryResult::REASONS, true))
                ->toBeTrue("Query [{$key}] has_data is false but reason [{$result['reason']}] is not in QueryResult::REASONS.");
        }
    }
});

test('AssistantDemoSeeder aligns store IDs, names, and slugs correctly', function () {
    $this->seed(DatabaseSeeder::class);

    $lagos = Store::where('name', 'Lagos Flagship')->first();
    $abuja = Store::where('name', 'Abuja Branch')->first();

    expect($lagos)->not->toBeNull()
        ->and($lagos->slug)->toBe('lagos-flagship')
        ->and($lagos->id)->toBe(2)
        ->and($abuja)->not->toBeNull()
        ->and($abuja->slug)->toBe('abuja-branch')
        ->and($abuja->id)->toBe(1);
});

test('CustomerDebtsQuery includes debt dated 60 days ago even when date range is this week', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $customer = PosCustomer::create(['vendor_id' => $vendor->id, 'name' => 'Old Debtor']);

    PosCustomerLedgerEntry::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'pos_customer_id' => $customer->id,
        'direction' => PosCustomerLedgerEntry::DIRECTION_CHARGE,
        'amount' => 450.00,
        'occurred_at' => now()->subDays(60)->toDateString(),
    ]);

    $query = new CustomerDebtsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfWeek(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['total_owed'])->toBe(450.0)
        ->and($res['debtor_count'])->toBe(1);
});

test('SupplierOweingsQuery includes unpaid procurement from 90 days ago when querying this month and excludes full procurements', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $supplier = Supplier::create(['vendor_id' => $vendor->id, 'name' => 'Supplier Co']);

    $unpaid = Procurement::create([
        'reference' => 'PROC-OLD-'.Str::random(6),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'created_by' => $user->id,
        'total_cost' => 5000.00,
        'amount_paid' => 2000.00,
        'payment_status' => 'part_payment',
        'status' => Procurement::STATUS_APPROVED,
    ]);
    $unpaid->created_at = now()->subDays(90);
    $unpaid->saveQuietly();

    $paid = Procurement::create([
        'reference' => 'PROC-FULL-'.Str::random(6),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'created_by' => $user->id,
        'total_cost' => 3000.00,
        'amount_paid' => 3000.00,
        'payment_status' => 'full',
        'status' => Procurement::STATUS_APPROVED,
    ]);
    $paid->created_at = now()->subDays(5);
    $paid->saveQuietly();

    $query = new SupplierOweingsQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfMonth(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['total_unpaid'])->toBe(3000.0)
        ->and($res['invoices_count'])->toBe(1);
});

test('VendorPayoutsDueQuery returns pending total including payouts dated 90 days ago', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $payout = VendorPayout::create([
        'vendor_id' => $vendor->id,
        'amount' => 750.00,
        'bank_name' => 'Zenith Bank',
        'account_number' => '1234567890',
        'account_name' => 'Test Vendor',
        'status' => 'pending',
    ]);
    $payout->created_at = now()->subDays(90);
    $payout->saveQuietly();

    $query = new VendorPayoutsDueQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfWeek(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['pending_total'])->toBe(750.0);
});

test('ProfitQuery returns total_revenue, total_cost, and profit in naira', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $category = Category::create(['name' => 'General', 'slug' => 'general-'.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Gadget A',
        'cost_price' => 2000.00,
        'price' => 5000.00,
    ]);

    $sale = PosSale::create([
        'reference' => 'POS-PROF-'.uniqid(),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $user->id,
        'subtotal' => 10000.00,
        'discount_amount' => 1000.00,
        'vat_amount' => 0,
        'total' => 9000.00,
        'payment_method' => 'cash',
        'amount_tendered' => 9000.00,
        'change_given' => 0,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => 'Gadget A',
        'unit_price' => 5000.00,
        'unit_cost' => 2000.00,
        'cost_total' => 4000.00,
        'quantity' => 2,
        'discount_amount' => 0,
        'total' => 10000.00,
    ]);

    $query = new ProfitQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfDay(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['total_revenue'])->toBe(9000.0) // 9,000 Naira
        ->and($res['total_cost'])->toBe(4000.0)   // 4,000 Naira
        ->and($res['profit'])->toBe(5000.0);      // 5,000 Naira
});

test('CustomerHistoryQuery finds customer by fuzzy name and returns last purchases in naira', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $customer = PosCustomer::create([
        'vendor_id' => $vendor->id,
        'name' => 'Chidi Okonkwo',
        'phone' => '08012345678',
    ]);

    $category = Category::create(['name' => 'Phones', 'slug' => 'phones-'.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'iPhone Charger',
        'cost_price' => 1000.00,
        'price' => 3500.00,
    ]);

    $sale = PosSale::create([
        'reference' => 'POS-CUST-'.uniqid(),
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'cashier_id' => $user->id,
        'subtotal' => 7000.00,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => 7000.00,
        'payment_method' => 'cash',
        'amount_tendered' => 7000.00,
        'change_given' => 0,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => 'iPhone Charger',
        'unit_price' => 3500.00,
        'unit_cost' => 1000.00,
        'cost_total' => 2000.00,
        'quantity' => 2,
        'discount_amount' => 0,
        'total' => 7000.00,
    ]);

    $query = new CustomerHistoryQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfMonth(), now(), [
        'customer_name' => 'Okonkwo',
    ]);

    expect($res['has_data'])->toBeTrue()
        ->and($res['customer_name'])->toBe('Chidi Okonkwo')
        ->and($res['purchases'])->toHaveCount(1)
        ->and($res['purchases'][0]['store_name'])->toBe($store->name)
        ->and($res['purchases'][0]['product_name'])->toBe('iPhone Charger')
        ->and($res['purchases'][0]['quantity'])->toBe(2)
        ->and($res['purchases'][0]['total_amount'])->toBe(7000.0);
});

test('CustomerHistoryQuery searches across all stores of the vendor and returns store_name', function () {
    [$user, $vendor, $storeA] = assistantQueriesVendor();
    $storeB = Store::create([
        'vendor_id' => $vendor->id,
        'name' => 'Branch Store B',
        'slug' => 'branch-b-'.uniqid(),
    ]);

    $customer = PosCustomer::create([
        'vendor_id' => $vendor->id,
        'name' => 'Emeka Eze',
        'phone' => '08099998888',
    ]);

    $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics-'.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'name' => 'Wireless Mouse',
        'cost_price' => 1500.00,
        'price' => 4000.00,
    ]);

    // Seed sale in Store A
    $sale = PosSale::create([
        'reference' => 'POS-CROSS-'.uniqid(),
        'vendor_id' => $vendor->id,
        'store_id' => $storeA->id,
        'customer_id' => $customer->id,
        'cashier_id' => $user->id,
        'subtotal' => 4000.00,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => 4000.00,
        'payment_method' => 'cash',
        'amount_tendered' => 4000.00,
        'change_given' => 0,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => 'Wireless Mouse',
        'unit_price' => 4000.00,
        'unit_cost' => 1500.00,
        'cost_total' => 1500.00,
        'quantity' => 1,
        'discount_amount' => 0,
        'total' => 4000.00,
    ]);

    // Query scoped to Store B
    $query = new CustomerHistoryQuery;
    $res = $query->execute(['store_id' => $storeB->id], now()->startOfMonth(), now(), [
        'customer_name' => 'Emeka Eze',
    ]);

    expect($res['has_data'])->toBeTrue()
        ->and($res['customer_name'])->toBe('Emeka Eze')
        ->and($res['purchases'])->toHaveCount(1)
        ->and($res['purchases'][0]['store_name'])->toBe($storeA->name)
        ->and($res['purchases'][0]['product_name'])->toBe('Wireless Mouse')
        ->and($res['purchases'][0]['total_amount'])->toBe(4000.0);
});

test('CustomerHistoryQuery returns not_configured when customer_name is missing or empty', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    $query = new CustomerHistoryQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfMonth(), now(), []);

    expect($res['has_data'])->toBeFalse()
        ->and($res['reason'])->toBe('not_configured');

    $resEmpty = $query->execute(['store_id' => $store->id], now()->startOfMonth(), now(), ['customer_name' => '   ']);

    expect($resEmpty['has_data'])->toBeFalse()
        ->and($resEmpty['reason'])->toBe('not_configured');
});

test('ExpenseSummaryQuery returns total expenses in naira and count for store', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();

    TillExpense::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'logged_by' => $user->id,
        'amount' => 1500.00,
        'reason' => 'Generator fuel',
        'spent_at' => now(),
    ]);

    TillExpense::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'logged_by' => $user->id,
        'amount' => 500.00,
        'reason' => 'Drinking water',
        'spent_at' => now(),
    ]);

    $query = new ExpenseSummaryQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfDay(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['total_expenses'])->toBe(2000.0)
        ->and($res['count'])->toBe(2);
});

test('CashierShortageQuery returns cash variance shortages with cashier name and naira', function () {
    [$user, $vendor, $store] = assistantQueriesVendor();
    $cashier = User::factory()->create(['name' => 'Adaeze Cashier']);

    $session = PosSession::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'cashier_id' => $cashier->id,
        'business_date' => now()->toDateString(),
        'opening_float' => 1000.00,
        'expected_cash' => 5000.00,
        'counted_cash' => 4500.00,
        'cash_variance' => -500.00,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
    ]);

    PosZReport::create([
        'vendor_id' => $vendor->id,
        'pos_session_id' => $session->id,
        'cashier_id' => $cashier->id,
        'report_date' => now()->toDateString(),
        'cash_sales' => 4000.00,
        'card_sales' => 0,
        'bank_transfer_sales' => 0,
        'total_sales' => 4000.00,
        'opening_float' => 1000.00,
        'cash_expected' => 5000.00,
        'cash_counted' => 4500.00,
        'cash_variance' => -500.00,
        'generated_at' => now(),
    ]);

    $query = new CashierShortageQuery;
    $res = $query->execute(['store_id' => $store->id], now()->startOfDay(), now());

    expect($res['has_data'])->toBeTrue()
        ->and($res['shortages'])->toHaveCount(1)
        ->and($res['shortages'][0]['cashier_name'])->toBe('Adaeze Cashier')
        ->and($res['shortages'][0]['variance'])->toBe(-500.0)
        ->and($res['shortages'][0]['date'])->toBe(now()->format('Y-m-d'));
});
