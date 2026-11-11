<?php

use App\Actions\Pos\RecordSaleReversalAction;
use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\FinancialLedgerEntry;
use App\Models\Lga;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosSale;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\State;
use App\Models\StockCostLayer;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\StockValuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    ->use(RefreshDatabase::class)
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

/**
 * Void a sale the way production does.
 *
 * Fixtures used to fake this with $sale->update(['status' => 'voided']), which
 * the immutability guard now refuses. Routed through the real action rather
 * than written round the guard, so a suite that needs a voided sale is also
 * proving the only path that can produce one still works.
 */
function voidSaleInTest(PosSale $sale, ?string $reason = null): PosSale
{
    app(RecordSaleReversalAction::class)->void(
        sale: $sale,
        actor: User::find($sale->cashier_id) ?? User::factory()->create(),
        reason: $reason ?? 'Voided by test fixture',
    );

    return $sale->refresh();
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
    $owner = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $owner->id, 'name' => 'Credit Store', 'pos_min_margin_percent' => 0,
    ]);
    $store = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'store_id' => $store->id,
        'category_id' => Category::create(['name' => 'C'.uniqid()])->id,
        'name' => 'Credit Widget',
        'price' => 10000,
        'cost_price' => 6000,
        'allow_pos_price_override' => false,
        'stock_quantity' => 50,
        'reserved_stock' => 0,
        'status' => 'published',
        'show_in_pos' => true,
    ]);

    $customer = PosCustomer::create([
        'vendor_id' => $vendor->id, 'name' => 'Ada Obi', 'phone' => '08031234567',
    ]);

    $owner->stores()->syncWithoutDetaching([$store->id]);

    return compact('owner', 'vendor', 'store', 'product', 'customer');
}

function debtSalePayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'vendor_id' => $ctx['vendor']->id,
        'customer_id' => $ctx['customer']->id,
        'items' => [[
            'product_id' => $ctx['product']->id,
            'product_name' => 'Credit Widget',
            'unit_price' => 10000.0,
            'quantity' => 1,
            'total' => 10000.0,
        ]],
        'vat_rate' => 0,
        'payment_method' => 'debt',
        'amount_tendered' => 0,
        'payments' => null,
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

    FinancialAccount::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Cash Drawer', 'type' => 'cash',
        'opening_balance' => 0, 'is_active' => true,
    ]);

    if ($owed > 0) {
        PosCustomerLedgerEntry::create([
            'pos_customer_id' => $ctx['customer']->id,
            'vendor_id' => $ctx['vendor']->id,
            'direction' => 'charge',
            'amount' => $owed,
            'store_id' => $ctx['store']->id,
            'created_by' => $ctx['owner']->id,
            'occurred_at' => '2026-08-01',
            'description' => 'Credit sale — seed',
        ]);
    }

    return $ctx;
}

function cashIn(int $vendorId): float
{
    return (float) FinancialLedgerEntry::whereIn(
        'financial_account_id',
        FinancialAccount::where('vendor_id', $vendorId)->pluck('id')
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

/*
|--------------------------------------------------------------------------
| Stock cost layer (FIFO) helpers
|--------------------------------------------------------------------------
|
| Shared by the batch-valuation and cost-of-goods-sold suites, here for the
| same reason as the debt helpers above: Pest only sees a function from
| another test file when that file happens to load first, so a suite run on
| its own would otherwise fail on an undefined function.
|
*/

/** A vendor holding no stock and no batches, ready to receive known deliveries. */
function layersContext(): array
{
    $ctx = debtTenderContext();

    ProductStoreStock::where('product_id', $ctx['product']->id)
        ->update(['quantity' => 0, 'reserved' => 0]);
    StockCostLayer::where('product_id', $ctx['product']->id)->delete();

    $ctx['product']->update(['cost_price' => null]);
    $ctx['product']->refresh();

    $ctx['supplier'] = Supplier::create([
        'vendor_id' => $ctx['vendor']->id, 'name' => 'Supplier',
    ]);

    return $ctx;
}

/** One delivery, received through the real approval path so it lays down a batch. */
function receiveBatch(array $ctx, int $quantity, float $unitCost): void
{
    $procurement = Procurement::create([
        'vendor_id' => $ctx['vendor']->id, 'supplier_id' => $ctx['supplier']->id,
        'created_by' => $ctx['owner']->id, 'store_id' => $ctx['store']->id,
        'status' => 'pending', 'total_cost' => $quantity * $unitCost, 'amount_paid' => 0,
        'payment_status' => 'unpaid', 'payment_method' => 'cash',
    ]);

    ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $ctx['product']->id,
        'quantity' => $quantity, 'unit_cost' => $unitCost, 'selling_price' => $unitCost * 2,
    ]);

    app(ApproveProcurementAction::class)
        ->execute($procurement, $ctx['store']->id);
}

function stockValue(array $ctx): float
{
    return StockValuation::forVendor($ctx['vendor']->id)['value'];
}

/*
|--------------------------------------------------------------------------
| Delivery locations
|--------------------------------------------------------------------------
|
| States and LGAs are seeded by a migration, so every test database has them.
| Checkout tests pick a real LGA by name rather than hard-coding ids.
|
*/
/** @return array{0: int, 1: int} [stateId, lgaId] */
function checkoutLocation(string $lga = 'Uyo', string $state = 'Akwa Ibom'): array
{
    $stateId = State::where('name', $state)->value('id');
    $lgaId = Lga::where('state_id', $stateId)->where('name', $lga)->value('id');

    if (! $stateId || ! $lgaId) {
        throw new RuntimeException("No LGA {$lga} in {$state}.");
    }

    return [$stateId, $lgaId];
}

/*
|--------------------------------------------------------------------------
| In-app tips (engagement)
|--------------------------------------------------------------------------
|
| A vendor with an owner and a storekeeper on the real default roles, and
| helpers for the panel context TipEligibility reads (Filament's canAccess()
| looks at the signed-in user and the current tenant).
|
*/

/** @return array{owner: User, storekeeper: User, vendor: Vendor, store: Store} */
function engagementVendor(string $name = 'Tips Shop'): array
{
    (new Database\Seeders\VendorPermissionsSeeder)->run();

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => $name.' '.uniqid()]);
    $store = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();

    $storekeeper = User::factory()->create();
    $vendor->users()->attach($storekeeper->id);
    $storekeeper->stores()->syncWithoutDetaching([$store->id]);

    setPermissionsTeamId($vendor->id);
    $storekeeper->assignRole(
        Spatie\Permission\Models\Role::where('name', 'storekeeper')->where('team_id', $vendor->id)->firstOrFail()
    );
    $storekeeper->unsetRelation('roles')->unsetRelation('permissions');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return compact('owner', 'storekeeper', 'vendor', 'store');
}

/** Sign in as $user inside $vendor's panel, the way a real panel request is. */
function actInTipsPanel(User $user, ?Vendor $vendor, string $panel = 'vendor'): void
{
    test()->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel($panel));
    Filament\Facades\Filament::setTenant($vendor);
    setPermissionsTeamId($vendor?->id);
}

function tipsContext(User $user, ?Vendor $vendor, ?string $route = 'filament.vendor.pages.dashboard', string $panel = 'vendor'): App\Support\Engagement\TipContext
{
    actInTipsPanel($user, $vendor, $panel);

    return new App\Support\Engagement\TipContext($user, $vendor, $panel, $route);
}

/** An active tip with sensible defaults; override anything. */
function makeTip(array $attributes = []): App\Models\Tip
{
    return App\Models\Tip::create(array_merge([
        'name' => 'Tip '.uniqid(),
        'type' => App\Models\Tip::TYPE_TIP,
        'title' => 'Did you know?',
        'message' => 'Something useful.',
        'panels' => ['vendor'],
        'status' => App\Models\Tip::STATUS_ACTIVE,
        'condition' => 'always',
        'cooldown_days' => 3,
    ], $attributes));
}

function logTipEvent(App\Models\Tip $tip, User $user, ?Vendor $vendor, string $event, mixed $at = null): App\Models\TipEvent
{
    $row = new App\Models\TipEvent([
        'tip_id' => $tip->id,
        'user_id' => $user->id,
        'vendor_id' => $vendor?->id,
        'event' => $event,
    ]);
    $row->created_at = $at ?? now();
    $row->save();

    return $row;
}

/** A debt charge recorded by $by at $at, the row CustomerDebtsFeature counts. */
function recordTestDebt(array $ctx, User $by, mixed $at = null): PosCustomerLedgerEntry
{
    $customer = PosCustomer::firstOrCreate(
        ['vendor_id' => $ctx['vendor']->id, 'phone' => '0803'.random_int(1000000, 9999999)],
        ['name' => 'Debtor'],
    );

    $entry = new PosCustomerLedgerEntry([
        'pos_customer_id' => $customer->id,
        'vendor_id' => $ctx['vendor']->id,
        'direction' => 'charge',
        'amount' => 5000,
        'store_id' => $ctx['store']->id,
        'created_by' => $by->id,
        'occurred_at' => '2026-11-01',
        'description' => 'Credit sale — test',
    ]);
    $entry->created_at = $at ?? now();
    $entry->save();

    return $entry;
}
