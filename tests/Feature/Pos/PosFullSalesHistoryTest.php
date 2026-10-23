<?php

use App\Models\Category;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\VendorRoles;
use App\Support\Pos\TillProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

// The till's full sales history: a cashier's own sales over any window, with
// totals for the window and for each trading day.

function fullHistoryContext(): array
{
    (new VendorPermissionsSeeder())->run();

    $owner  = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Full History Store']);
    VendorRoles::seedFor($vendor);

    $cashier = User::factory()->create();
    $other   = User::factory()->create();
    $vendor->users()->syncWithoutDetaching([$cashier->id, $other->id]);

    setPermissionsTeamId($vendor->id);
    $cashier->assignRole('storekeeper');
    $other->assignRole('storekeeper');

    return compact('owner', 'vendor', 'cashier', 'other');
}

/**
 * A sale as the till would have rung it. $at is Lagos wall-clock time.
 *
 * @param  array<string, mixed>  $over
 * @param  array<int, array{0: string, 1: float}>  $legs  tender rows for a mixed sale
 */
function historySale(Vendor $vendor, User $cashier, string $at, array $over = [], array $legs = [], string $product = 'Phone Case'): PosSale
{
    static $n = 0;
    $n++;

    $total = $over['total'] ?? 10750;

    $sale = PosSale::create(array_merge([
        'reference'       => 'POS-H' . $n,
        'vendor_id'       => $vendor->id,
        'cashier_id'      => $cashier->id,
        'subtotal'        => 10000,
        'discount_amount' => 0,
        'vat_amount'      => 750,
        'total'           => $total,
        'payment_method'  => 'cash',
        'amount_tendered' => $total,
        'change_given'    => 0,
        'status'          => 'completed',
        'synced'          => true,
        'completed_at'    => CarbonImmutable::parse($at, 'Africa/Lagos')->utc(),
    ], $over));

    $item = Product::firstOrCreate(
        ['vendor_id' => $vendor->id, 'name' => $product],
        ['category_id' => Category::firstOrCreate(['name' => 'History'])->id, 'price' => 10000, 'stock_quantity' => 100, 'status' => 'published'],
    );

    PosSaleItem::create([
        'pos_sale_id'  => $sale->id,
        'product_id'   => $item->id,
        'product_name' => $product,
        'unit_price'   => $sale->subtotal,
        'quantity'     => 1,
        'total'        => $sale->subtotal,
    ]);

    foreach ($legs as [$method, $amount]) {
        PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => $method, 'amount' => $amount]);
    }

    return $sale;
}

function historyRefund(PosSale $sale, User $cashier, float $amount, string $method, string $at): PosReturn
{
    static $n = 0;
    $n++;

    $return = PosReturn::create([
        'reference'        => 'RET-H' . $n,
        'vendor_id'        => $sale->vendor_id,
        'original_sale_id' => $sale->id,
        'cashier_id'       => $cashier->id,
        'return_items'     => [],
        'refund_amount'    => $amount,
        'refund_method'    => $method,
    ]);

    // created_at is what dates a refund, so it is set rather than left to now().
    $return->forceFill(['created_at' => CarbonImmutable::parse($at, 'Africa/Lagos')->utc()])->saveQuietly();

    return $return;
}

function historyUrl(Vendor $vendor, array $params = []): string
{
    return '/api/pos/sales/history?' . http_build_query(array_merge(['vendor_id' => $vendor->id], $params));
}

it('refuses a cashier whose role does not carry the permission', function () {
    $ctx = fullHistoryContext();

    Role::where('name', 'storekeeper')->where('team_id', $ctx['vendor']->id)->first()
        ->revokePermissionTo('view_sales_history');

    Sanctum::actingAs($ctx['cashier']);

    $this->getJson(historyUrl($ctx['vendor']))->assertForbidden();
});

it('lets the owner in without any role', function () {
    $ctx = fullHistoryContext();

    Sanctum::actingAs($ctx['owner']);

    $this->getJson(historyUrl($ctx['vendor']))->assertSuccessful();
});

it('gives every default till role the permission', function () {
    expect(VendorRoles::rolesWith('view_sales_history'))
        ->toEqualCanonicalizing(VendorRoles::rolesWith('access_pos'));
});

it('only lists the signed-in cashier\'s own sales at this vendor', function () {
    $ctx = fullHistoryContext();
    $elsewhere = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Elsewhere']);

    $mine     = historySale($ctx['vendor'], $ctx['cashier'], '2026-10-20 10:00');
    $theirs   = historySale($ctx['vendor'], $ctx['other'], '2026-10-20 10:00');
    $otherBiz = historySale($elsewhere, $ctx['cashier'], '2026-10-20 10:00');

    Sanctum::actingAs($ctx['cashier']);

    $refs = collect($this->getJson(historyUrl($ctx['vendor'], ['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertSuccessful()
        ->json('data'))->pluck('reference');

    expect($refs)->toContain($mine->reference)
        ->not->toContain($theirs->reference)
        ->not->toContain($otherBiz->reference);
});

it('files a sale rung just after midnight in Lagos under that day, not the day before', function () {
    $ctx = fullHistoryContext();

    // 00:30 in Lagos is 23:30 UTC the previous evening.
    $sale = historySale($ctx['vendor'], $ctx['cashier'], '2026-10-22 00:30');

    Sanctum::actingAs($ctx['cashier']);

    $on22 = $this->getJson(historyUrl($ctx['vendor'], ['preset' => 'custom', 'from' => '2026-10-22', 'to' => '2026-10-22']))->json();
    $on21 = $this->getJson(historyUrl($ctx['vendor'], ['preset' => 'custom', 'from' => '2026-10-21', 'to' => '2026-10-21']))->json();

    expect(collect($on22['data'])->pluck('reference'))->toContain($sale->reference)
        ->and($on22['data'][0]['business_date'])->toBe('2026-10-22')
        ->and($on22['days'])->toHaveKey('2026-10-22')
        ->and($on21['data'])->toBeEmpty();
});

it('reads "today" on the Lagos clock', function () {
    $ctx = fullHistoryContext();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:45', 'Africa/Lagos'));

    $sale = historySale($ctx['vendor'], $ctx['cashier'], '2026-10-22 00:30');

    Sanctum::actingAs($ctx['cashier']);

    $response = $this->getJson(historyUrl($ctx['vendor'], ['preset' => 'today']))->json();

    expect($response['period'])->toMatchArray(['from' => '2026-10-22', 'to' => '2026-10-22'])
        ->and(collect($response['data'])->pluck('reference'))->toContain($sale->reference);
});

it('adds up what was collected the way the cash-up does', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];

    historySale($v, $c, '2026-10-20 09:00', ['payment_method' => 'cash', 'total' => 10750]);
    historySale($v, $c, '2026-10-20 10:00', ['payment_method' => 'card', 'total' => 10750]);
    // Mixed: 6,000 cash handed over for 5,000 of it, so 1,000 change.
    historySale($v, $c, '2026-10-20 11:00', ['payment_method' => 'split', 'total' => 10750, 'change_given' => 1000], [['cash', 6000], ['bank_transfer', 5750]]);
    historySale($v, $c, '2026-10-20 12:00', ['payment_method' => 'debt', 'total' => 10750]);
    $voided = historySale($v, $c, '2026-10-20 13:00', ['payment_method' => 'cash', 'total' => 10750, 'status' => 'voided']);
    $refunded = historySale($v, $c, '2026-10-20 14:00', ['payment_method' => 'cash', 'total' => 10750, 'status' => 'partial_refund']);

    historyRefund($refunded, $c, 2000, 'cash', '2026-10-20 15:00');
    historyRefund($refunded, $c, 500, 'store_credit', '2026-10-20 15:30');

    Sanctum::actingAs($c);

    $summary = $this->getJson(historyUrl($v, ['preset' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-20']))
        ->assertSuccessful()
        ->json('summary');

    expect($summary['sales_count'])->toBe(5)
        ->and($summary['tenders'])->toEqual([
            'cash'          => 10750 + 10750 + 6000 - 1000,
            'card'          => 10750,
            'bank_transfer' => 5750,
            'debt'          => 10750,
        ])
        ->and($summary['collected'])->toEqual(26500 + 10750 + 5750)
        ->and($summary['credit'])->toEqual(10750)
        ->and($summary['voided'])->toEqual(['count' => 1, 'total' => 10750])
        ->and($summary['refunds'])->toEqual(['count' => 2, 'total' => 2500, 'money_out' => 2000])
        // Store credit is a refund but not money out of the drawer.
        ->and($summary['net_collected'])->toEqual(43000 - 2000)
        // Five live sales at 10,000 before VAT, less every refund.
        ->and($summary['revenue'])->toEqual(50000 - 2500)
        ->and($summary['vat'])->toEqual(3750);
});

it('totals only the chosen tender, including the cash half of a mixed sale', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];

    $cash = historySale($v, $c, '2026-10-20 09:00', ['payment_method' => 'cash', 'total' => 10750]);
    $card = historySale($v, $c, '2026-10-20 10:00', ['payment_method' => 'card', 'total' => 10750]);
    $mixed = historySale($v, $c, '2026-10-20 11:00', ['payment_method' => 'split', 'total' => 10750, 'change_given' => 1000], [['cash', 6000], ['card', 5750]]);

    historyRefund($cash, $c, 1000, 'cash', '2026-10-20 12:00');
    historyRefund($card, $c, 700, 'card', '2026-10-20 12:00');

    Sanctum::actingAs($c);

    $response = $this->getJson(historyUrl($v, ['preset' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-20', 'method' => 'cash']))->json();

    expect(collect($response['data'])->pluck('reference')->sort()->values()->all())
        ->toEqual(collect([$cash->reference, $mixed->reference])->sort()->values()->all())
        ->and($response['summary']['collected'])->toEqual(10750 + 6000 - 1000)
        ->and($response['summary']['tenders']['card'])->toEqual(0)
        ->and($response['summary']['refunds'])->toEqual(['count' => 1, 'total' => 1000, 'money_out' => 1000])
        // A whole-sale figure cannot be split by tender, so none is given.
        ->and($response['summary']['revenue'])->toBeNull();
});

it('finds a sale by reference or by product name', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'Africa/Lagos'));

    $charger = historySale($v, $c, '2026-10-20 09:00', [], [], 'Fast Charger');
    $case    = historySale($v, $c, '2026-10-20 10:00', [], [], 'Phone Case');

    Sanctum::actingAs($c);

    $byProduct = $this->getJson(historyUrl($v, ['preset' => 'this_year', 'q' => 'charger']))->json();
    $byRef     = $this->getJson(historyUrl($v, ['preset' => 'this_year', 'q' => $case->reference]))->json();

    expect(collect($byProduct['data'])->pluck('reference')->all())->toBe([$charger->reference])
        ->and(collect($byRef['data'])->pluck('reference')->all())->toBe([$case->reference])
        // A search names sales, so there is no honest refund figure to give.
        ->and($byProduct['summary']['refunds'])->toBeNull();
});

it('filters by status, with refunded covering part refunds', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];

    historySale($v, $c, '2026-10-20 09:00');
    $voided  = historySale($v, $c, '2026-10-20 10:00', ['status' => 'voided']);
    $partial = historySale($v, $c, '2026-10-20 11:00', ['status' => 'partial_refund']);
    $full    = historySale($v, $c, '2026-10-20 12:00', ['status' => 'refunded']);

    Sanctum::actingAs($c);

    $range = ['preset' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-20'];

    $refunded = $this->getJson(historyUrl($v, $range + ['status' => 'refunded']))->json('data');
    $voidedOnly = $this->getJson(historyUrl($v, $range + ['status' => 'voided']))->json();

    expect(collect($refunded)->pluck('reference')->sort()->values()->all())
        ->toEqual(collect([$partial->reference, $full->reference])->sort()->values()->all())
        ->and(collect($voidedOnly['data'])->pluck('reference')->all())->toBe([$voided->reference])
        ->and($voidedOnly['summary']['sales_count'])->toBe(0)
        ->and($voidedOnly['summary']['voided']['count'])->toBe(1);
});

it('gives each day on the page its own totals, which add up to the range', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];

    historySale($v, $c, '2026-10-19 09:00', ['total' => 1000, 'amount_tendered' => 1000]);
    historySale($v, $c, '2026-10-20 09:00', ['total' => 2000, 'amount_tendered' => 2000]);
    historySale($v, $c, '2026-10-20 18:00', ['total' => 3000, 'amount_tendered' => 3000]);

    Sanctum::actingAs($c);

    $response = $this->getJson(historyUrl($v, ['preset' => 'custom', 'from' => '2026-10-19', 'to' => '2026-10-20']))->json();

    expect(array_keys($response['days']))->toEqual(['2026-10-20', '2026-10-19'])
        ->and($response['days']['2026-10-20']['collected'])->toEqual(5000)
        ->and($response['days']['2026-10-20']['sales_count'])->toBe(2)
        ->and($response['days']['2026-10-19']['collected'])->toEqual(1000)
        ->and($response['summary']['collected'])->toEqual(6000);
});

it('pages through a long history and sends the range summary once', function () {
    $ctx = fullHistoryContext();
    [$v, $c] = [$ctx['vendor'], $ctx['cashier']];

    foreach (range(1, 30) as $i) {
        historySale($v, $c, sprintf('2026-09-%02d 10:00', $i));
    }

    Sanctum::actingAs($c);

    $range = ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-10-31'];

    $first  = $this->getJson(historyUrl($v, $range))->json();
    $second = $this->getJson(historyUrl($v, $range + ['page' => 2]))->json();

    expect($first['data'])->toHaveCount(25)
        ->and($first['last_page'])->toBe(2)
        ->and($first['summary']['sales_count'])->toBe(30)
        ->and($second['data'])->toHaveCount(5)
        ->and($second['summary'])->toBeNull()
        ->and($first['data'][0]['business_date'])->toBe('2026-09-30');
});

it('tells the till whether to offer the full history', function () {
    $ctx = fullHistoryContext();

    expect(TillProfile::for($ctx['cashier'], $ctx['vendor'])['can']['view_sales_history'])->toBeTrue();

    Role::where('name', 'storekeeper')->where('team_id', $ctx['vendor']->id)->first()
        ->revokePermissionTo('view_sales_history');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    expect(TillProfile::for($ctx['cashier']->fresh(), $ctx['vendor'])['can']['view_sales_history'])->toBeFalse();
});

it('grants the permission on deploy to every role and person who can already use the till', function () {
    (new VendorPermissionsSeeder())->run();
    $vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Deploy Store']);

    setPermissionsTeamId($vendor->id);

    // A role the vendor made themselves, and one that never touches the till.
    $custom = Role::create(['name' => 'weekend_till', 'guard_name' => 'web', 'team_id' => $vendor->id]);
    $custom->givePermissionTo('access_pos');
    $catalogue = Role::create(['name' => 'catalogue_only', 'guard_name' => 'web', 'team_id' => $vendor->id]);
    $catalogue->givePermissionTo('view_products');

    $direct = User::factory()->create();
    $direct->givePermissionTo('access_pos');

    // As production will be: the permission does not exist yet.
    DB::table('permissions')->where('name', 'view_sales_history')->delete();
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_10_27_100000_grant_view_sales_history_permission.php');
    $migration->up();
    // Twice, as a re-run would: nothing doubles up or fails.
    $migration->up();

    expect($custom->fresh()->hasPermissionTo('view_sales_history'))->toBeTrue()
        ->and($catalogue->fresh()->hasPermissionTo('view_sales_history'))->toBeFalse()
        ->and($direct->fresh()->hasVendorPermission($vendor->id, 'view_sales_history'))->toBeTrue();
});
