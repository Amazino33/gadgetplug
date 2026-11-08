<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Actions\Assistant\Queries\CustomerDebtsQuery;
use App\Actions\Assistant\Queries\ProfitQuery;
use App\Actions\Assistant\Queries\RevenueVsPreviousQuery;
use App\Actions\Assistant\Queries\SalesByDayQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\StockValueQuery;
use App\Models\AssistantGlobalSetting;
use App\Models\Category;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantScope;
use App\Services\Assistant\PromptBuilder;
use App\Services\Inventory\StoreStockMetrics;
use App\Services\Pos\CustomerDebtService;
use App\Services\Reporting\SalesReportService;
use App\Support\Naira;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Phase 1.2: the assistant's figures are the dashboard's figures — the same
 * service, the same store, the same range — and every amount it hands the
 * model is naira with a display string worked out in code.
 *
 * Fixtures include refunds on purpose: the queries' old SQL summed sales
 * and never netted refunds, so it disagreed with the dashboard exactly there.
 * The clock is midday Lagos: the Lagos-day item of 1.2 lands separately.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-06 12:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor, main: Store, branch: Store, product: Product} */
function parityVendor(): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Parity Phones']);
    $main = $vendor->defaultStore;
    $branch = Store::create(['vendor_id' => $vendor->id, 'name' => 'Parity Ikeja']);

    foreach ([$main, $branch] as $store) {
        $store->forceFill(['created_at' => now()->subDays(90)])->save();
    }

    $product = Product::create([
        'vendor_id' => $vendor->id, 'store_id' => $main->id,
        'category_id' => Category::firstOrCreate(['name' => 'Parity Phones'])->id,
        'name' => 'Parity Phone', 'sku' => 'PAR-'.Str::random(6),
        'price' => 10000, 'cost_price' => 7000, 'stock_quantity' => 20, 'status' => 'published',
    ]);

    return ['owner' => $owner, 'vendor' => $vendor, 'main' => $main->fresh(), 'branch' => $branch->fresh(), 'product' => $product];
}

function paritySale(array $ctx, Store $store, float $unitPrice, int $qty, CarbonImmutable $at, float $unitCost = 7000): PosSale
{
    $subtotal = $unitPrice * $qty;

    $sale = PosSale::create([
        'reference' => 'POS-'.Str::random(10), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $store->id,
        'cashier_id' => $ctx['owner']->id, 'subtotal' => $subtotal, 'discount_amount' => 0, 'vat_amount' => 0,
        'total' => $subtotal, 'payment_method' => 'cash', 'status' => 'completed', 'completed_at' => $at,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id, 'product_id' => $ctx['product']->id, 'product_name' => 'Parity Phone',
        'unit_price' => $unitPrice, 'unit_cost' => $unitCost, 'quantity' => $qty, 'total' => $subtotal,
    ]);

    return $sale;
}

function parityRefund(array $ctx, PosSale $sale, float $amount, CarbonImmutable $at): void
{
    PosReturn::create([
        'reference' => 'RET-'.Str::random(8), 'vendor_id' => $ctx['vendor']->id, 'original_sale_id' => $sale->id,
        'cashier_id' => $ctx['owner']->id, 'return_items' => [], 'refund_amount' => $amount,
        'refund_method' => 'cash', 'created_at' => $at,
    ]);
}

function parityRun(array $ctx, BaseAssistantQuery $query, array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
{
    test()->actingAs($ctx['owner']);

    return AssistantScope::for($ctx['owner'], $ctx['vendor'])->run(
        fn () => $query->execute(count($storeIds) === 1 ? ['store_id' => $storeIds[0]] : ['store_ids' => $storeIds], $from, $to)
    );
}

/** A week of trading across both branches, with a refund in each. */
function parityWeek(array $ctx): array
{
    $from = CarbonImmutable::now()->subDays(6)->startOfDay();
    $to = CarbonImmutable::now();

    $a = paritySale($ctx, $ctx['main'], 10000, 3, $from->addDay()->setTime(10, 0));
    paritySale($ctx, $ctx['main'], 12000, 1, $from->addDays(3)->setTime(15, 0));
    $b = paritySale($ctx, $ctx['branch'], 9000, 2, $from->addDays(2)->setTime(11, 0));
    parityRefund($ctx, $a, 10000, $from->addDays(2)->setTime(9, 0));
    parityRefund($ctx, $b, 4500, $from->addDays(4)->setTime(9, 0));

    return [$from, $to];
}

test('sales_summary is the dashboard figure for one store, refunds netted', function () {
    $ctx = parityVendor();
    [$from, $to] = parityWeek($ctx);

    $assistant = parityRun($ctx, new SalesSummaryQuery, [$ctx['main']->id], $from, $to);
    $dashboard = app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to, $ctx['main']->id);

    expect($assistant['revenue'])->toEqual(round($dashboard['revenue'], 2))
        ->and($assistant['revenue'])->toEqual(32000.0) // 30,000 + 12,000 − 10,000 refunded
        ->and($assistant['revenue_display'])->toBe('₦32,000')
        ->and($assistant['orders'])->toBe($dashboard['orders']);
});

test('sales_summary for all of a business\'s stores is the dashboard "All Stores" figure', function () {
    $ctx = parityVendor();
    [$from, $to] = parityWeek($ctx);

    $assistant = parityRun($ctx, new SalesSummaryQuery, [$ctx['main']->id, $ctx['branch']->id], $from, $to);
    $dashboard = app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to, null);

    expect($assistant['revenue'])->toEqual(round($dashboard['revenue'], 2))
        ->and($assistant['revenue'])->toEqual(45500.0); // 32,000 + 18,000 − 4,500
});

test('profit is the dashboard profit and margin, worked out in code', function () {
    $ctx = parityVendor();
    [$from, $to] = parityWeek($ctx);

    $assistant = parityRun($ctx, new ProfitQuery, [$ctx['main']->id], $from, $to);
    $dashboard = app(SalesReportService::class)->summary($ctx['vendor']->id, $from, $to, $ctx['main']->id);

    expect($assistant['total_revenue'])->toEqual(round($dashboard['revenue'], 2))
        ->and($assistant['total_cost'])->toEqual(round($dashboard['cost'], 2))
        ->and($assistant['profit'])->toEqual(round($dashboard['profit'], 2))
        ->and($assistant['margin_percent'])->toEqual(round($dashboard['margin'], 1))
        ->and($assistant['profit_display'])->toBe(Naira::format($dashboard['profit'], '₦0'))
        ->and($assistant['margin_percent_display'])->toEndWith('%');
});

test('revenue_vs_previous compares two dashboard figures and works out the change in code', function () {
    $ctx = parityVendor();
    $to = CarbonImmutable::now();
    $from = $to->subDays(7);

    paritySale($ctx, $ctx['main'], 20000, 1, $to->subDays(2));
    paritySale($ctx, $ctx['main'], 10000, 1, $from->subDays(3));

    $assistant = parityRun($ctx, new RevenueVsPreviousQuery, [$ctx['main']->id], $from, $to);
    $reports = app(SalesReportService::class);

    expect($assistant['current_revenue'])->toEqual(round($reports->summary($ctx['vendor']->id, $from, $to, $ctx['main']->id)['revenue'], 2))
        ->and($assistant['previous_revenue'])->toEqual(round($reports->summary($ctx['vendor']->id, $from->subSeconds($from->diffInSeconds($to)), $from, $ctx['main']->id)['revenue'], 2))
        ->and($assistant['difference_display'])->toBe('₦10,000')
        ->and($assistant['percentage_change'])->toEqual(100.0)
        ->and($assistant['percentage_change_display'])->toBe('+100.0%');
});

test('sales_by_day gives each Lagos day the dashboard figure for that day', function () {
    $ctx = parityVendor();
    [$from, $to] = parityWeek($ctx);

    $assistant = parityRun($ctx, new SalesByDayQuery, [$ctx['main']->id], $from, $to);
    $reports = app(SalesReportService::class);

    expect($assistant['days'])->not->toBeEmpty();

    foreach ($assistant['days'] as $day) {
        [$start, $end] = BusinessDate::boundsFor($day['date']);
        $dashboard = $reports->summary($ctx['vendor']->id, $start, $end, $ctx['main']->id);

        expect($day['revenue'])->toEqual(round($dashboard['revenue'], 2))
            ->and($day['revenue_display'])->toBe(Naira::format($dashboard['revenue'], '₦0'));
    }

    // And for the whole business, the dashboard revenue trend's own days.
    $whole = parityRun($ctx, new SalesByDayQuery, [$ctx['main']->id, $ctx['branch']->id], $from, $to);
    $series = $reports->dailySeries($ctx['vendor']->id, $from, $to);
    $trend = array_combine(
        array_map(fn ($i) => BusinessDate::of($from->addDays($i)), array_keys($series['revenue'])),
        $series['revenue'],
    );

    foreach ($whole['days'] as $day) {
        expect($day['revenue'])->toEqual($trend[$day['date']]);
    }
});

test('customer_debts is the debt ledger\'s figure: vendor-wide outstanding, per-branch aging', function () {
    $ctx = parityVendor();
    $ada = PosCustomer::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '08030000001']);

    foreach ([[$ctx['main'], 'charge', 20000, 40], [$ctx['branch'], 'charge', 5000, 5], [$ctx['branch'], 'payment', -8000, 2]] as [$store, $direction, $amount, $daysAgo]) {
        PosCustomerLedgerEntry::create([
            'pos_customer_id' => $ada->id, 'vendor_id' => $ctx['vendor']->id, 'store_id' => $store->id,
            'direction' => $direction, 'amount' => $amount, 'created_by' => $ctx['owner']->id,
            'occurred_at' => now()->subDays($daysAgo)->toDateString(), 'description' => 'parity',
        ]);
    }

    $debts = app(CustomerDebtService::class);
    $whole = parityRun($ctx, new CustomerDebtsQuery, [$ctx['main']->id, $ctx['branch']->id], now()->subDays(7), now());
    $main = parityRun($ctx, new CustomerDebtsQuery, [$ctx['main']->id], now()->subDays(7), now());

    expect($whole['total_owed'])->toEqual(round($debts->vendorOutstanding($ctx['vendor']->id), 2))
        ->and($whole['total_owed'])->toEqual(17000.0)
        ->and($whole['total_owed_display'])->toBe('₦17,000')
        // The payment at the branch clears the oldest charge (at main) first.
        ->and($main['total_owed'])->toEqual(round($debts->aged($ctx['vendor']->id, $ctx['main']->id)['total'], 2))
        ->and(collect($whole['aging'])->firstWhere('bucket', '31_60')['amount'])->toEqual(12000.0)
        ->and($whole['debtors'][0]['name'])->toBe('Ada Obi');
});

test('stock_value is the figure the store screens show (StoreStockMetrics)', function () {
    $ctx = parityVendor();
    ProductStoreStock::where('product_id', $ctx['product']->id)->update(['quantity' => 12]);

    $assistant = parityRun($ctx, new StockValueQuery, [$ctx['main']->id], now()->subDay(), now());
    $metrics = StoreStockMetrics::forStores([$ctx['main']->id])->get($ctx['main']->id);

    expect($assistant['total_cost_value'])->toEqual(round($metrics->cost_value, 2))
        ->and($assistant['total_cost_value_display'])->toBe('₦84,000')
        ->and($assistant['total_retail_value'])->toEqual(round($metrics->retail_value, 2));
});

test('no amount reaches the model in kobo, and the prompt no longer asks it to convert', function () {
    $ctx = parityVendor();
    [$from, $to] = parityWeek($ctx);

    $result = parityRun($ctx, new SalesSummaryQuery, [$ctx['main']->id], $from, $to);
    $prompt = (new PromptBuilder)->systemPrompt(now(), null, []);

    expect($result['revenue'])->toBeFloat()
        ->and($result['revenue_display'])->toStartWith('₦')
        ->and($prompt)->not->toContain('kobo')
        ->and($prompt)->not->toContain('KOBO')
        ->and($prompt)->toContain('quote each *_display value exactly')
        ->and($prompt)->toContain('Never calculate money yourself');
});
