<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Actions\Assistant\Queries\MySalesQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Models\AssistantGlobalSetting;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Assistant\AssistantPeriod;
use App\Services\Assistant\AssistantScope;
use App\Services\Pos\CashierSalesHistory;
use Carbon\CarbonImmutable;
use Database\Seeders\VendorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
 * Phase 1.2, own sales for till-only staff (owner decision, 2026-11-08):
 * a cashier without report access can ask about what they rang themselves,
 * and gets exactly the till's My Sales figures, labelled as their own.
 * Shop totals stay refused, with no hint at the figure.
 */

beforeEach(function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-10 15:00', 'Africa/Lagos'));
});

/** @return array{owner: User, vendor: Vendor, store: Store, cashier: User, other: User} */
function mySalesVendor(): array
{
    (new VendorPermissionsSeeder)->run();
    AssistantGlobalSetting::current()->update(['is_enabled' => true]);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Till Test']);
    $store = $vendor->defaultStore;
    $store->forceFill(['created_at' => now()->subDays(60)])->save();

    $make = function () use ($vendor, $store) {
        $user = User::factory()->create();
        $vendor->users()->syncWithoutDetaching([$user->id]);
        setPermissionsTeamId($vendor->id);
        $role = Role::firstOrCreate(['name' => 'till-only-test', 'guard_name' => 'web', 'team_id' => $vendor->id]);
        $role->givePermissionTo(['use_ai_assistant', 'access_pos']);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $store->users()->syncWithoutDetaching([$user->id]);

        return $user->fresh();
    };

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $store->fresh(), 'cashier' => $make(), 'other' => $make()];
}

function mySalesRing(array $ctx, User $cashier, float $total, string $method, string $status = 'completed', ?CarbonImmutable $at = null): PosSale
{
    return PosSale::create([
        'reference' => 'POS-'.Str::random(8), 'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'cashier_id' => $cashier->id, 'subtotal' => $total, 'discount_amount' => 0, 'vat_amount' => 0,
        'total' => $total, 'payment_method' => $method, 'status' => $status, 'completed_at' => ($at ?? CarbonImmutable::now()->subHours(2))->utc(),
    ]);
}

function mySalesAsk(User $user, Vendor $vendor, BaseAssistantQuery $query, Store $store, string $period = 'today'): array
{
    test()->actingAs($user);
    $p = AssistantPeriod::named($period);

    return AssistantScope::for($user, $vendor)->run(fn () => $query->execute(['store_id' => $store->id], $p->from(), $p->to()));
}

test('a till-only cashier gets their own sales, the same figures as My Sales', function () {
    $ctx = mySalesVendor();
    mySalesRing($ctx, $ctx['cashier'], 10000, 'cash');
    mySalesRing($ctx, $ctx['cashier'], 25000, 'card');
    mySalesRing($ctx, $ctx['cashier'], 7000, 'debt');
    mySalesRing($ctx, $ctx['cashier'], 3000, 'cash', 'voided');
    $refunded = mySalesRing($ctx, $ctx['cashier'], 4000, 'cash');
    PosReturn::create([
        'reference' => 'RET-'.Str::random(6), 'vendor_id' => $ctx['vendor']->id, 'original_sale_id' => $refunded->id,
        'cashier_id' => $ctx['cashier']->id, 'return_items' => [], 'refund_amount' => 4000, 'refund_method' => 'cash',
        'created_at' => CarbonImmutable::now()->subHour()->utc(),
    ]);
    mySalesRing($ctx, $ctx['other'], 99000, 'cash'); // a colleague's sale
    mySalesRing($ctx, $ctx['cashier'], 50000, 'cash', 'completed', CarbonImmutable::now()->subDay()); // yesterday

    $mine = mySalesAsk($ctx['cashier'], $ctx['vendor'], new MySalesQuery, $ctx['store']);
    $p = AssistantPeriod::named('today');
    $till = app(CashierSalesHistory::class)->summary($ctx['vendor']->id, $ctx['cashier']->id, $p->from(), $p->to());

    expect($mine['whose'])->toContain('your own sales')
        ->and($mine['sales_count'])->toBe($till['sales_count'])
        ->and($mine['revenue'])->toEqual(round($till['revenue'], 2))
        ->and($mine['collected'])->toEqual(round($till['collected'], 2))
        ->and($mine['on_credit'])->toEqual(7000.0)
        ->and($mine['cash_display'])->toBe('₦14,000')
        ->and($mine['card_display'])->toBe('₦25,000')
        ->and($mine['refunds_display'])->toBe('₦4,000')
        ->and($mine['voided_count'])->toBe(1);
});

test('the same cashier is refused shop totals, with nothing that hints at them', function () {
    $ctx = mySalesVendor();
    mySalesRing($ctx, $ctx['other'], 99000, 'cash');

    expect(mySalesAsk($ctx['cashier'], $ctx['vendor'], new SalesSummaryQuery, $ctx['store']))
        ->toBe(['has_data' => false, 'reason' => 'no_access']);
});

test('it is always the asker\'s own sales: an owner asking gets what the owner rang, not the shop', function () {
    $ctx = mySalesVendor();
    mySalesRing($ctx, $ctx['owner'], 5000, 'cash');
    mySalesRing($ctx, $ctx['cashier'], 40000, 'cash');

    $owner = mySalesAsk($ctx['owner'], $ctx['vendor'], new MySalesQuery, $ctx['store']);

    expect($owner['sales_count'])->toBe(1)
        ->and($owner['revenue_display'])->toBe('₦5,000');
});

test('yesterday is the Lagos yesterday', function () {
    $ctx = mySalesVendor();
    mySalesRing($ctx, $ctx['cashier'], 1000, 'cash', 'completed', CarbonImmutable::parse('2026-11-09 00:30', 'Africa/Lagos'));
    mySalesRing($ctx, $ctx['cashier'], 2000, 'cash', 'completed', CarbonImmutable::parse('2026-11-08 23:30', 'Africa/Lagos'));

    expect(mySalesAsk($ctx['cashier'], $ctx['vendor'], new MySalesQuery, $ctx['store'], 'yesterday')['revenue'])->toEqual(1000.0);
});
