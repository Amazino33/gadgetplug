<?php

use App\Actions\Finance\RecordExpenseAction;
use App\Actions\Pos\ChargeCustomerDebtAction;
use App\Actions\Pos\RecordCustomerPaymentAction;
use App\Livewire\Vendor\HomeDashboard;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\PosDebtPayment;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Cash\CashUpExpectation;
use App\Services\Cash\StoreReconciliation;
use App\Services\FinancialLedger;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\VendorDailySummary;
use App\Support\Pos\BusinessDate;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

require_once __DIR__.'/../CashUp/Helpers.php';

uses(RefreshDatabase::class);

/*
 * The trading day is the Lagos day. Stored timestamps are UTC, and Lagos is
 * UTC+1, so 00:00–01:00 Lagos is still "yesterday" by the server's clock.
 * Every check here runs at 00:30 Lagos (where the UTC date is the day before)
 * and at 23:30 Lagos (where both agree), on Saturday 7 November 2026.
 */

dataset('lagos clock', [
    '00:30 Lagos (UTC date is still the 6th)' => ['2026-11-07 00:30'],
    '23:30 Lagos' => ['2026-11-07 23:30'],
]);

function atLagos(string $wallClock): void
{
    test()->travelTo(CarbonImmutable::parse($wallClock, 'Africa/Lagos'));
}

/** A UTC instant for a Lagos wall-clock time, as a sale's completed_at is stored. */
function lagosInstant(string $wallClock): CarbonImmutable
{
    return CarbonImmutable::parse($wallClock, 'Africa/Lagos')->utc();
}

function boundaryVendor(): array
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Boundary Shop '.uniqid()]);

    return ['owner' => $owner, 'vendor' => $vendor, 'store' => $vendor->defaultStore];
}

function boundarySale(array $ctx, float $total, CarbonImmutable $at, string $method = 'cash', ?PosCustomer $customer = null): PosSale
{
    return PosSale::create([
        'reference' => 'POS-'.Str::random(10),
        'vendor_id' => $ctx['vendor']->id,
        'store_id' => $ctx['store']->id,
        'cashier_id' => $ctx['owner']->id,
        'customer_id' => $customer?->id,
        'subtotal' => $total,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'total' => $total,
        'payment_method' => $method,
        'status' => 'completed',
        'completed_at' => $at,
    ]);
}

// ── The helper itself ────────────────────────────────────────────────────────

test('ReportPeriod "today" is the Lagos day, converted to stored time', function (string $clock) {
    atLagos($clock);

    $today = ReportPeriod::fromFilters(['preset' => 'today']);

    expect($today->from->utc()->toDateTimeString())->toBe('2026-11-06 23:00:00')
        ->and($today->to->utc()->toDateTimeString())->toBe('2026-11-07 22:59:59')
        ->and(BusinessDate::today())->toBe('2026-11-07');
})->with('lagos clock');

test('ReportPeriod presets are unchanged by being built on BusinessDate', function () {
    atLagos('2026-11-07 00:30');

    $range = fn (string $preset, array $extra = []) => array_map(
        fn ($d) => $d->utc()->toDateTimeString(),
        [ReportPeriod::fromFilters(['preset' => $preset] + $extra)->from, ReportPeriod::fromFilters(['preset' => $preset] + $extra)->to],
    );

    expect($range('yesterday'))->toBe(['2026-11-05 23:00:00', '2026-11-06 22:59:59'])
        ->and($range('this_week'))->toBe(['2026-11-01 23:00:00', '2026-11-07 22:59:59'])   // week starts Monday 2 Nov
        ->and($range('last_7'))->toBe(['2026-10-31 23:00:00', '2026-11-07 22:59:59'])
        ->and($range('this_month'))->toBe(['2026-10-31 23:00:00', '2026-11-07 22:59:59'])
        ->and($range('custom', ['from' => '2026-11-03', 'to' => '2026-11-01']))->toBe(['2026-10-31 23:00:00', '2026-11-03 22:59:59']);
});

// ── Group B: Lagos-zoned bounds that were never converted ────────────────────

test('the home dashboard counts sales rung between 00:00 and 01:00 Lagos as today', function (string $clock) {
    atLagos($clock);
    $ctx = boundaryVendor();

    boundarySale($ctx, 1000, lagosInstant('2026-11-07 00:10'));   // today, first hour
    boundarySale($ctx, 5000, lagosInstant('2026-11-06 23:50'));   // yesterday
    if ($clock === '2026-11-07 23:30') {
        boundarySale($ctx, 2000, lagosInstant('2026-11-07 23:20')); // today, last hour
    }

    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::bootCurrentPanel();
    Filament::setTenant($ctx['vendor']);

    $summary = Livewire::test(HomeDashboard::class)->viewData('summary');

    expect((float) $summary['revenue'])->toBe($clock === '2026-11-07 00:30' ? 1000.0 : 3000.0);
})->with('lagos clock');

test("the owner's daily WhatsApp summary covers the whole Lagos day, first hour included", function (string $clock) {
    // Run on the 8th; "yesterday" on the shop's calendar is the 7th.
    atLagos(str_replace('2026-11-07', '2026-11-08', $clock));
    $ctx = boundaryVendor();

    boundarySale($ctx, 1000, lagosInstant('2026-11-07 00:10'));   // the 7th, first hour
    boundarySale($ctx, 2000, lagosInstant('2026-11-07 23:50'));   // the 7th, last minutes
    boundarySale($ctx, 4000, lagosInstant('2026-11-06 23:50'));   // the 6th
    boundarySale($ctx, 8000, lagosInstant('2026-11-08 00:10'));   // the 8th

    $settings = VendorNotificationSetting::create([
        'vendor_id' => $ctx['vendor']->id,
        'owner_whatsapp' => '08030000000',
        'daily_summary_time' => '00:00:00',
    ])->fresh();

    $covers = $settings->dailySummaryDueFor(now());
    expect($covers->toDateString())->toBe('2026-11-07');

    $summary = app(VendorDailySummary::class)->build($ctx['vendor'], $covers);

    expect((float) $summary['totals']['revenue'])->toBe(3000.0)
        ->and($summary['totals']['orders'])->toBe(2);
})->with('lagos clock');

// ── Group D: business-dated columns stamped with the UTC date ────────────────

test('a debt repayment at the till lands in that Lagos day\'s cash-up', function (string $clock) {
    atLagos($clock);
    $ctx = cashUpContext();
    $ctx['vendor']->users()->syncWithoutDetaching([$ctx['cashier']->id]);

    $customer = PosCustomer::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Ada Obi', 'phone' => '08031234567']);
    PosCustomerLedgerEntry::create([
        'pos_customer_id' => $customer->id,
        'vendor_id' => $ctx['vendor']->id,
        'direction' => 'charge',
        'amount' => 10000,
        'store_id' => $ctx['store']->id,
        'created_by' => $ctx['owner']->id,
        'occurred_at' => '2026-11-01',
        'description' => 'Credit sale — seed',
    ]);

    Sanctum::actingAs($ctx['cashier']);
    test()->postJson("/api/pos/customers/{$customer->id}/repayments", [
        'vendor_id' => $ctx['vendor']->id, 'amount' => 4000, 'method' => 'cash',
    ])->assertCreated();

    expect(PosDebtPayment::sole()->collected_at->toDateString())->toBe('2026-11-07');

    $breakdown = app(CashUpExpectation::class)->compute(
        vendorId: $ctx['vendor']->id,
        storeId: $ctx['store']->id,
        cashierId: $ctx['cashier']->id,
        businessDate: '2026-11-07',
        openingFloat: 0,
    );

    expect(collect($breakdown->cashLines)->firstWhere('key', 'debt_repayments')['amount'])->toBe(4000.0);
})->with('lagos clock');

test('customer-ledger, expense and money-ledger entries are dated by the Lagos day', function (string $clock) {
    atLagos($clock);
    $ctx = boundaryVendor();
    $customer = PosCustomer::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Bola', 'phone' => '08039999999']);

    // A credit sale rung in the first Lagos hour of the 7th.
    $sale = boundarySale($ctx, 6000, lagosInstant('2026-11-07 00:10'), 'debt', $customer);
    PosSalePayment::create(['pos_sale_id' => $sale->id, 'method' => 'debt', 'amount' => 6000]);
    $charge = app(ChargeCustomerDebtAction::class)->execute($sale);

    $payment = app(RecordCustomerPaymentAction::class)->execute($customer, 1000, $ctx['owner'], $ctx['store']->id);

    $expense = app(RecordExpenseAction::class)->execute(
        vendorId: $ctx['vendor']->id, category: 'other', amount: 500, recordedBy: $ctx['owner'],
    );

    $cash = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->first();
    $entry = FinancialLedger::postEntry($cash, 'in', 700, description: 'Boundary test');

    expect($charge->occurred_at->toDateString())->toBe('2026-11-07')
        ->and($payment->occurred_at->toDateString())->toBe('2026-11-07')
        ->and(Expense::find($expense->id)->incurred_at->toDateString())->toBe('2026-11-07')
        ->and($entry->occurred_at->toDateString())->toBe('2026-11-07');
})->with('lagos clock');

// ── Group E: a correct range turned into the wrong dates ─────────────────────

test("Checkmate's expense range starts on the right Lagos day", function (string $clock) {
    atLagos($clock);
    $ctx = boundaryVendor();

    foreach (['2026-11-06' => 900, '2026-11-07' => 250] as $day => $amount) {
        Expense::create([
            'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
            'category' => 'other', 'amount' => $amount, 'incurred_at' => $day,
        ]);
    }

    $today = ReportPeriod::fromFilters(['preset' => 'today']);
    $figures = app(StoreReconciliation::class)->forStore(Store::find($ctx['store']->id), $today->from, $today->to);

    // Yesterday's ₦900 used to ride in on "today" for the first Lagos hour.
    expect($figures['position']['expenses'])->toBe(250.0);
})->with('lagos clock');

test('an account balance "as of now" includes what was booked today, Lagos time', function (string $clock) {
    atLagos($clock);
    $ctx = boundaryVendor();
    $cash = FinancialAccount::where('vendor_id', $ctx['vendor']->id)->where('type', 'cash')->first();

    FinancialLedger::postEntry($cash, 'in', 700, description: 'Booked today');

    expect($cash->balance(now()))->toBe(700.0);
})->with('lagos clock');
