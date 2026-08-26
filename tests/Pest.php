<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Customer debt fixtures
|--------------------------------------------------------------------------
|
| Shared by the debt tender and revenue-recognition suites. They live here
| rather than in one of those files because Pest only sees a function from
| another test file when that file happens to be loaded first — which made
| running either suite on its own fail.
|
*/

function debtTenderContext(): array
{
    $owner  = App\Models\User::factory()->create();
    $vendor = App\Models\Vendor::create([
        'user_id' => $owner->id, 'name' => 'Credit Store', 'pos_min_margin_percent' => 0,
    ]);
    $store = App\Models\Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    $product = App\Models\Product::create([
        'vendor_id'                => $vendor->id,
        'store_id'                 => $store->id,
        'category_id'              => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name'                     => 'Credit Widget',
        'price'                    => 10000,
        'cost_price'               => 6000,
        'allow_pos_price_override' => false,
        'stock_quantity'           => 50,
        'reserved_stock'           => 0,
        'status'                   => 'published',
        'show_in_pos'              => true,
    ]);

    $customer = App\Models\PosCustomer::create([
        'vendor_id' => $vendor->id, 'name' => 'Ada Obi', 'phone' => '08031234567',
    ]);

    $owner->stores()->syncWithoutDetaching([$store->id]);

    return compact('owner', 'vendor', 'store', 'product', 'customer');
}

function debtSalePayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'vendor_id'      => $ctx['vendor']->id,
        'customer_id'    => $ctx['customer']->id,
        'items'          => [[
            'product_id'   => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'unit_price'   => 10000.0,
            'quantity'     => 1,
            'total'        => 10000.0,
        ]],
        'vat_rate'        => 0,
        'payment_method'  => 'debt',
        'amount_tendered' => 0,
        'payments'        => null,
    ], $overrides);
}

/**
 * A vendor with a cash account and a customer already owing money, plus the
 * panel-context helper. Shared by the repayment and write-off suites, so they
 * live here rather than in whichever file happens to load first.
 */
function repaymentContext(float $owed = 10000.0): array
{
    $ctx = debtTenderContext();

    App\Models\FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Cash Drawer', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    if ($owed > 0) {
        App\Models\PosCustomerLedgerEntry::create([
            'pos_customer_id' => $ctx['customer']->id,
            'vendor_id'       => $ctx['vendor']->id,
            'direction'       => 'charge',
            'amount'          => $owed,
            'store_id'        => $ctx['store']->id,
            'created_by'      => $ctx['owner']->id,
            'occurred_at'     => '2026-08-01',
            'description'     => 'Credit sale — seed',
        ]);
    }

    return $ctx;
}

function cashIn(int $vendorId): float
{
    return (float) App\Models\FinancialLedgerEntry::whereIn(
        'financial_account_id',
        App\Models\FinancialAccount::where('vendor_id', $vendorId)->pluck('id')
    )->where('direction', 'in')->sum('amount');
}

/**
 * Setting the tenant alone is not enough: tenant-aware route generation needs
 * the current panel too, or rendering a table with a row action fails with
 * "Route not defined".
 */
function actAsDebtOwner(array $ctx): void
{
    test()->actingAs($ctx['owner']);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('vendor'));
    Filament\Facades\Filament::setTenant($ctx['vendor']);
}
