<?php

use App\Models\Category;
use App\Models\DeliveryMessage;
use App\Models\Expense;
use App\Models\MessageTemplate;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNotificationSetting;
use App\Services\Messaging\DailySummaryNotifier;
use App\Services\Reporting\VendorDailySummary;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function setUpSummaryVendor(array $settings = []): array
{
    config(['services.messaging.whatsapp_driver' => 'log_null', 'services.messaging.sms_driver' => 'log_null']);

    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Summary Store']);
    MessageTemplateSeeder::forVendor($vendor);

    VendorNotificationSetting::forVendor($vendor)->update(array_merge([
        'owner_whatsapp' => '08055554444',
    ], $settings));

    $main = Store::create(['vendor_id' => $vendor->id, 'name' => 'Main Branch', 'slug' => 'main-branch', 'is_default' => true]);
    $second = Store::create(['vendor_id' => $vendor->id, 'name' => 'Ikot Branch', 'slug' => 'ikot-branch']);

    $category = Category::create(['name' => 'Summary Cat '.uniqid()]);
    $product = Product::create([
        'vendor_id' => $vendor->id, 'category_id' => $category->id,
        'name' => 'Power Bank', 'price' => 10000, 'cost_price' => 6000,
        'stock_quantity' => 50, 'status' => 'published',
    ]);

    return compact('owner', 'vendor', 'main', 'second', 'product');
}

/**
 * A completed till sale. $method 'split' expects $splits as [method => amount].
 */
function makeSummaryPosSale(array $data, int $storeId, float $subtotal, string $method, Carbon $at, array $splits = []): PosSale
{
    $vat = round($subtotal * 0.075, 2);

    $sale = PosSale::create([
        'reference' => 'POS-'.strtoupper(uniqid()),
        'vendor_id' => $data['vendor']->id,
        'store_id' => $storeId,
        'cashier_id' => $data['owner']->id,
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        'vat_amount' => $vat,
        'total' => $subtotal + $vat,
        'payment_method' => $method,
        'amount_tendered' => $subtotal + $vat,
        'change_given' => 0,
        'status' => 'completed',
        'completed_at' => $at,
    ]);

    PosSaleItem::create([
        'pos_sale_id' => $sale->id,
        'product_id' => $data['product']->id,
        'product_name' => 'Power Bank',
        'unit_price' => 10000,
        'unit_cost' => 6000,
        'quantity' => (int) max(1, round($subtotal / 10000)),
        'total' => $subtotal,
    ]);

    foreach ($splits as $splitMethod => $amount) {
        PosSalePayment::create([
            'pos_sale_id' => $sale->id,
            'method' => $splitMethod,
            'amount' => $amount,
        ]);
    }

    return $sale;
}

function summaryFor(array $data, ?Carbon $date = null): array
{
    return app(VendorDailySummary::class)->build($data['vendor']->fresh(), $date ?? Carbon::yesterday());
}

// --- Per-store sales ----------------------------------------------------

test('each store gets its own line with its own takings', function () {
    $data = setUpSummaryVendor();
    $at = Carbon::yesterday()->setTime(11, 0);

    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', $at);
    makeSummaryPosSale($data, $data['second']->id, 10000, 'card', $at);

    $summary = summaryFor($data);
    $byName = collect($summary['stores'])->keyBy('name');

    expect($byName['Main Branch']['revenue'])->toBe(30000.0)
        ->and($byName['Ikot Branch']['revenue'])->toBe(10000.0)
        ->and($summary['totals']['revenue'])->toBe(40000.0);
});

test('the default store is listed first', function () {
    $data = setUpSummaryVendor();

    expect(summaryFor($data)['stores'][0]['name'])->toBe('Main Branch');
});

test('sales from another day are not counted', function () {
    $data = setUpSummaryVendor();

    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', Carbon::yesterday()->subDays(3)->setTime(11, 0));

    expect(summaryFor($data)['totals']['revenue'])->toBe(0.0);
});

test('a voided sale is excluded', function () {
    $data = setUpSummaryVendor();
    $sale = makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', Carbon::yesterday()->setTime(11, 0));
    $sale->update(['status' => 'voided']);

    expect(summaryFor($data)['totals']['revenue'])->toBe(0.0);
});

// --- Payment breakdown --------------------------------------------------

test('money taken is split by tender type and is gross of VAT', function () {
    $data = setUpSummaryVendor();
    $at = Carbon::yesterday()->setTime(11, 0);

    makeSummaryPosSale($data, $data['main']->id, 10000, 'cash', $at);
    makeSummaryPosSale($data, $data['main']->id, 20000, 'card', $at);
    makeSummaryPosSale($data, $data['main']->id, 40000, 'bank_transfer', $at);

    $payments = summaryFor($data)['payments'];

    // Gross: 10,000 + 7.5% VAT = 10,750 etc.
    expect($payments['cash'])->toBe(10750.0)
        ->and($payments['card'])->toBe(21500.0)
        ->and($payments['bank_transfer'])->toBe(43000.0)
        ->and($payments['total'])->toBe(75250.0);
});

// The bug this guards: grouping pos_sales.payment_method alone drops split
// sales into a phantom bucket, understating both the drawer and the bank.
test('a split sale is attributed to each tender it was actually paid with', function () {
    $data = setUpSummaryVendor();
    $at = Carbon::yesterday()->setTime(12, 0);

    // 20,000 + 1,500 VAT = 21,500 paid as 6,500 cash + 15,000 transfer.
    makeSummaryPosSale($data, $data['main']->id, 20000, 'split', $at, [
        'cash' => 6500,
        'bank_transfer' => 15000,
    ]);

    $payments = summaryFor($data)['payments'];

    expect($payments['cash'])->toBe(6500.0)
        ->and($payments['bank_transfer'])->toBe(15000.0)
        ->and($payments['card'])->toBe(0.0)
        ->and($payments['total'])->toBe(21500.0);
});

test('split and direct sales add together correctly', function () {
    $data = setUpSummaryVendor();
    $at = Carbon::yesterday()->setTime(12, 0);

    makeSummaryPosSale($data, $data['main']->id, 10000, 'cash', $at);
    makeSummaryPosSale($data, $data['main']->id, 20000, 'split', $at, ['cash' => 6500, 'card' => 15000]);

    $payments = summaryFor($data)['payments'];

    expect($payments['cash'])->toBe(17250.0)
        ->and($payments['card'])->toBe(15000.0);
});

// --- Expenses and procurement ------------------------------------------

test('expenses recorded that day are totalled by category', function () {
    $data = setUpSummaryVendor();

    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 5000, 'incurred_at' => Carbon::yesterday()->toDateString()]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 2500, 'incurred_at' => Carbon::yesterday()->toDateString()]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 1000, 'incurred_at' => Carbon::yesterday()->toDateString()]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 9999, 'incurred_at' => Carbon::yesterday()->subDay()->toDateString()]);

    $expenses = summaryFor($data)['expenses'];

    expect($expenses['by_category']['advertising'])->toBe(7500.0)
        ->and($expenses['by_category']['other'])->toBe(1000.0)
        ->and($expenses['total'])->toBe(8500.0);
});

// An unpaid expense still counts: the owner asked what was recorded.
test('an expense recorded but not yet posted still counts', function () {
    $data = setUpSummaryVendor();

    Expense::create([
        'vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 4000,
        'incurred_at' => Carbon::yesterday()->toDateString(), 'posted_at' => null,
    ]);

    expect(summaryFor($data)['expenses']['total'])->toBe(4000.0);
});

test('procurement recorded that day reports committed, paid and outstanding', function () {
    $data = setUpSummaryVendor();
    $supplier = Supplier::create(['vendor_id' => $data['vendor']->id, 'name' => 'Lagos Wholesale']);

    $proc = Procurement::create([
        'vendor_id' => $data['vendor']->id, 'supplier_id' => $supplier->id,
        'total_cost' => 200000, 'amount_paid' => 120000,
        'payment_status' => 'part_payment', 'status' => 'approved',
        'created_by' => $data['owner']->id,
    ]);
    Procurement::whereKey($proc->id)->update(['created_at' => Carbon::yesterday()->setTime(9, 0)]);

    $procurement = summaryFor($data)['procurement'];

    expect($procurement['count'])->toBe(1)
        ->and($procurement['total_cost'])->toBe(200000.0)
        ->and($procurement['amount_paid'])->toBe(120000.0)
        ->and($procurement['outstanding'])->toBe(80000.0);
});

test('a voided procurement is not reported as committed money', function () {
    $data = setUpSummaryVendor();
    $supplier = Supplier::create(['vendor_id' => $data['vendor']->id, 'name' => 'Lagos Wholesale']);

    $proc = Procurement::create([
        'vendor_id' => $data['vendor']->id, 'supplier_id' => $supplier->id,
        'total_cost' => 200000, 'amount_paid' => 0,
        'payment_status' => 'credit', 'status' => 'voided',
        'created_by' => $data['owner']->id,
    ]);
    Procurement::whereKey($proc->id)->update(['created_at' => Carbon::yesterday()->setTime(9, 0)]);

    expect(summaryFor($data)['procurement']['count'])->toBe(0);
});

// --- Empty day ----------------------------------------------------------

test('a day with no sales, expenses or procurement is flagged empty', function () {
    expect(summaryFor(setUpSummaryVendor())['is_empty'])->toBeTrue();
});

test('a day with only an expense is not empty', function () {
    $data = setUpSummaryVendor();
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 500, 'incurred_at' => Carbon::yesterday()->toDateString()]);

    expect(summaryFor($data)['is_empty'])->toBeFalse();
});

// --- Rendered message ---------------------------------------------------

test('the sent summary contains store lines, tender split, profit and spend', function () {
    $data = setUpSummaryVendor();
    $at = Carbon::yesterday()->setTime(11, 0);

    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', $at);
    makeSummaryPosSale($data, $data['second']->id, 10000, 'card', $at);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'advertising', 'amount' => 5000, 'incurred_at' => Carbon::yesterday()->toDateString()]);

    $message = app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday());

    expect($message)->not->toBeNull()
        ->and($message->recipient_type)->toBe('owner')
        ->and($message->to_number)->toBe('2348055554444')
        ->and($message->order_id)->toBeNull()
        ->and($message->status)->toBe('sent');

    expect($message->body)
        ->toContain('Daily Summary')
        ->toContain('Main Branch: ₦30,000.00')
        ->toContain('Ikot Branch: ₦10,000.00')
        ->toContain('Cash: ₦32,250.00')
        ->toContain('Card: ₦10,750.00')
        ->toContain('Advertising: ₦5,000.00')
        ->not->toContain('{{');
});

test('nothing is sent when there is no owner number', function () {
    $data = setUpSummaryVendor(['owner_whatsapp' => null]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 500, 'incurred_at' => Carbon::yesterday()->toDateString()]);

    expect(app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday()))->toBeNull();
});

test('nothing is sent when the summary toggle is off', function () {
    $data = setUpSummaryVendor(['notify_daily_summary' => false]);
    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 500, 'incurred_at' => Carbon::yesterday()->toDateString()]);

    expect(app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday()))->toBeNull();
});

test('a quiet day sends nothing unless forced', function () {
    $data = setUpSummaryVendor();

    expect(app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday()))->toBeNull()
        ->and(app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday(), force: true))->not->toBeNull();
});

test('an inactive template suppresses the summary', function () {
    $data = setUpSummaryVendor();
    MessageTemplate::where('vendor_id', $data['vendor']->id)
        ->where('key', 'vendor_daily_summary')
        ->update(['is_active' => false]);

    Expense::create(['vendor_id' => $data['vendor']->id, 'category' => 'other', 'amount' => 500, 'incurred_at' => Carbon::yesterday()->toDateString()]);

    expect(app(DailySummaryNotifier::class)->send($data['vendor'], Carbon::yesterday()))->toBeNull();
});

test('one vendor is never told about another vendors trading', function () {
    $mine = setUpSummaryVendor();
    $theirs = setUpSummaryVendor();

    makeSummaryPosSale($theirs, $theirs['main']->id, 50000, 'cash', Carbon::yesterday()->setTime(11, 0));

    expect(summaryFor($mine)['totals']['revenue'])->toBe(0.0)
        ->and(summaryFor($theirs)['totals']['revenue'])->toBe(50000.0);
});

// --- Scheduling ---------------------------------------------------------

// The configured hour is the shop's hour, not the server's. app.timezone is UTC
// and the business runs on WAT (UTC+1), so a 07:00 setting must fire at 06:00 UTC.
test('the send hour is read on the business clock, not the server clock', function () {
    config(['services.messaging.timezone' => 'Africa/Lagos']);

    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // 05:30 UTC = 06:30 Lagos — not yet.
    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 05:30', 'UTC')))->toBeNull();

    // 06:00 UTC = 07:00 Lagos — due.
    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 06:00', 'UTC')))->not->toBeNull();
});

test('quiet hours are read on the business clock too', function () {
    config(['services.messaging.timezone' => 'Africa/Lagos']);

    $data = setUpSummaryVendor([
        'quiet_hours_enabled' => true,
        'quiet_from' => '08:00:00',
        'quiet_until' => '20:00:00',
    ]);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // 07:30 UTC = 08:30 Lagos — inside the waking window.
    expect($settings->isQuietAt(Carbon::parse('2026-08-23 07:30', 'UTC')))->toBeFalse();

    // 19:30 UTC = 20:30 Lagos — past it.
    expect($settings->isQuietAt(Carbon::parse('2026-08-23 19:30', 'UTC')))->toBeTrue();
});

// Near midnight the server date and the shop date differ; summarising the
// server's "yesterday" would skip or repeat a whole day of trading.
test('the covered date is yesterday on the business calendar', function () {
    config(['services.messaging.timezone' => 'Africa/Lagos']);

    $data = setUpSummaryVendor(['daily_summary_time' => '00:30:00']);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // 23:45 UTC on the 23rd is already 00:45 on the 24th in Lagos, so the day
    // to report is the 23rd, not the 22nd.
    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 23:45', 'UTC'))?->toDateString())
        ->toBe('2026-08-23');
});

test('the summary is due once the configured hour has passed', function () {
    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    // Parsed in the business timezone so the test states which clock it means.
    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 06:30', 'Africa/Lagos')))->toBeNull()
        ->and($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 07:00', 'Africa/Lagos'))?->toDateString())->toBe('2026-08-22');
});

// A missed 07:00 tick must not skip the day entirely.
test('a late tick still sends the same day', function () {
    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 11:00'))?->toDateString())->toBe('2026-08-22');
});

test('a date already summarised is not sent again', function () {
    $data = setUpSummaryVendor([
        'daily_summary_time' => '07:00:00',
        'last_daily_summary_for' => '2026-08-22',
    ]);
    $settings = VendorNotificationSetting::forVendor($data['vendor'])->fresh();

    expect($settings->dailySummaryDueFor(Carbon::parse('2026-08-23 09:00')))->toBeNull()
        ->and($settings->dailySummaryDueFor(Carbon::parse('2026-08-24 09:00'))?->toDateString())->toBe('2026-08-23');
});

test('the command sends and stamps the watermark', function () {
    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', Carbon::yesterday()->setTime(11, 0));

    Carbon::setTestNow(Carbon::today()->setTime(8, 0));
    DeliveryMessage::truncate();

    $this->artisan('vendor:daily-summary')->assertSuccessful();

    expect(DeliveryMessage::where('recipient_type', 'owner')->count())->toBe(1)
        ->and(VendorNotificationSetting::forVendor($data['vendor'])->fresh()->last_daily_summary_for->toDateString())
        ->toBe(Carbon::yesterday()->toDateString());

    Carbon::setTestNow();
});

test('the command sends nothing before the configured hour', function () {
    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', Carbon::yesterday()->setTime(11, 0));

    Carbon::setTestNow(Carbon::today()->setTime(5, 0));
    DeliveryMessage::truncate();

    $this->artisan('vendor:daily-summary')->assertSuccessful();

    expect(DeliveryMessage::where('recipient_type', 'owner')->count())->toBe(0);

    Carbon::setTestNow();
});

test('running the command twice does not send twice', function () {
    $data = setUpSummaryVendor(['daily_summary_time' => '07:00:00']);
    makeSummaryPosSale($data, $data['main']->id, 30000, 'cash', Carbon::yesterday()->setTime(11, 0));

    Carbon::setTestNow(Carbon::today()->setTime(8, 0));
    DeliveryMessage::truncate();

    $this->artisan('vendor:daily-summary')->assertSuccessful();
    $this->artisan('vendor:daily-summary')->assertSuccessful();

    expect(DeliveryMessage::where('recipient_type', 'owner')->count())->toBe(1);

    Carbon::setTestNow();
});
