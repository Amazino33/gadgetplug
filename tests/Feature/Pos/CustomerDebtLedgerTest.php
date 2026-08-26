<?php

use App\Models\PosCustomer;
use App\Models\PosCustomerLedgerEntry;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function debtVendor(): Vendor
{
    return Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Debt Store ' . uniqid()]);
}

function debtCustomer(Vendor $vendor, string $name = 'Ada Obi'): PosCustomer
{
    return PosCustomer::create([
        'vendor_id' => $vendor->id,
        'name'      => $name,
        'phone'     => '0800' . random_int(1000000, 9999999),
    ]);
}

function debtEntry(PosCustomer $customer, string $direction, float $amount, array $attrs = []): PosCustomerLedgerEntry
{
    return PosCustomerLedgerEntry::create(array_merge([
        'pos_customer_id' => $customer->id,
        'vendor_id'       => $customer->vendor_id,
        'direction'       => $direction,
        'amount'          => $amount,
        'occurred_at'     => now()->toDateString(),
    ], $attrs));
}

// ─── Immutability ───────────────────────────────────────────────────────

test('a ledger row can never be updated', function () {
    $entry = debtEntry(debtCustomer(debtVendor()), 'charge', 5000);

    expect(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class);
});

test('a ledger row can never be deleted', function () {
    $entry = debtEntry(debtCustomer(debtVendor()), 'charge', 5000);

    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

test('a mass update across the table is blocked too, not just a single row', function () {
    $customer = debtCustomer(debtVendor());
    debtEntry($customer, 'charge', 5000);

    // Eloquent fires model events per row here, so the guard still applies.
    expect(fn () => PosCustomerLedgerEntry::where('pos_customer_id', $customer->id)->get()
        ->each->update(['amount' => 0]))->toThrow(LogicException::class);
});

// ─── Sign convention ────────────────────────────────────────────────────

test('a charge cannot be negative', function () {
    expect(fn () => debtEntry(debtCustomer(debtVendor()), 'charge', -100))
        ->toThrow(LogicException::class);
});

test('a payment cannot be positive', function () {
    expect(fn () => debtEntry(debtCustomer(debtVendor()), 'payment', 100))
        ->toThrow(LogicException::class);
});

test('a write-off cannot be positive', function () {
    expect(fn () => debtEntry(debtCustomer(debtVendor()), 'writeoff', 100))
        ->toThrow(LogicException::class);
});

test('an unknown direction is refused', function () {
    expect(fn () => debtEntry(debtCustomer(debtVendor()), 'refund', -100))
        ->toThrow(LogicException::class);
});

// ─── Derived outstanding ────────────────────────────────────────────────

test('outstanding is charges minus payments and write-offs', function () {
    $customer = debtCustomer(debtVendor());

    debtEntry($customer, 'charge', 10000);
    debtEntry($customer, 'charge', 5000);
    debtEntry($customer, 'payment', -3000);
    debtEntry($customer, 'writeoff', -2000);

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($customer->id))->toBe(10000.0)
        ->and($debt->totalCharged($customer->id))->toBe(15000.0)
        ->and($debt->totalPaid($customer->id))->toBe(3000.0)
        ->and($debt->totalWrittenOff($customer->id))->toBe(2000.0);
});

test('the summary agrees with the individual figures', function () {
    $customer = debtCustomer(debtVendor());

    debtEntry($customer, 'charge', 7500.50);
    debtEntry($customer, 'payment', -2500.25);

    expect(app(CustomerDebtService::class)->summary($customer->id))->toBe([
        'charged'     => 7500.50,
        'paid'        => 2500.25,
        'written_off' => 0.0,
        'outstanding' => 5000.25,
    ]);
});

test('a customer with no history owes nothing rather than erroring', function () {
    $customer = debtCustomer(debtVendor());
    $debt     = app(CustomerDebtService::class);

    expect($debt->outstanding($customer->id))->toBe(0.0)
        ->and($debt->owesAnything($customer->id))->toBeFalse()
        ->and($debt->summary($customer->id)['outstanding'])->toBe(0.0);
});

test('a fully paid debt reads as owing nothing', function () {
    $customer = debtCustomer(debtVendor());

    debtEntry($customer, 'charge', 4000);
    debtEntry($customer, 'payment', -4000);

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($customer->id))->toBe(0.0)
        ->and($debt->owesAnything($customer->id))->toBeFalse();
});

test('an overpayment reads as credit, not as a debt to chase', function () {
    $customer = debtCustomer(debtVendor());

    debtEntry($customer, 'charge', 1000);
    debtEntry($customer, 'payment', -1500);

    $debt = app(CustomerDebtService::class);

    expect($debt->outstanding($customer->id))->toBe(-500.0)
        ->and($debt->owesAnything($customer->id))->toBeFalse();
});

// ─── Vendor-wide reads ──────────────────────────────────────────────────

test('outstanding by customer lists only those who still owe', function () {
    $vendor = debtVendor();

    $owes    = debtCustomer($vendor, 'Owes Money');
    $settled = debtCustomer($vendor, 'All Square');

    debtEntry($owes, 'charge', 8000);
    debtEntry($owes, 'payment', -3000);

    debtEntry($settled, 'charge', 2000);
    debtEntry($settled, 'payment', -2000);

    $balances = app(CustomerDebtService::class)->outstandingByCustomer($vendor->id);

    expect($balances->keys()->all())->toBe([$owes->id])
        ->and($balances[$owes->id])->toBe(5000.0);
});

test('one vendor debt never appears under another', function () {
    $a = debtVendor();
    $b = debtVendor();

    debtEntry(debtCustomer($a), 'charge', 9000);
    debtEntry(debtCustomer($b), 'charge', 1000);

    $debt = app(CustomerDebtService::class);

    expect($debt->vendorOutstanding($a->id))->toBe(9000.0)
        ->and($debt->vendorOutstanding($b->id))->toBe(1000.0);
});

// ─── History ────────────────────────────────────────────────────────────

test('history reads oldest first with a running balance after each line', function () {
    $vendor   = debtVendor();
    $customer = debtCustomer($vendor);
    $store    = Store::where('vendor_id', $vendor->id)->where('is_default', true)->first();
    $staff    = User::factory()->create();

    debtEntry($customer, 'charge', 10000, [
        'occurred_at' => '2026-08-01', 'store_id' => $store->id, 'created_by' => $staff->id,
    ]);
    debtEntry($customer, 'payment', -4000, ['occurred_at' => '2026-08-10']);
    debtEntry($customer, 'charge', 2000, ['occurred_at' => '2026-08-20']);

    $history = app(CustomerDebtService::class)->history($customer->id);

    expect($history->pluck('running')->all())->toBe([10000.0, 6000.0, 8000.0])
        ->and($history->first()['entry']->store_id)->toBe($store->id)
        ->and($history->first()['entry']->created_by)->toBe($staff->id);
});

test('the running balance ends on the same figure as outstanding', function () {
    $customer = debtCustomer(debtVendor());

    debtEntry($customer, 'charge', 6000, ['occurred_at' => '2026-08-01']);
    debtEntry($customer, 'payment', -1500, ['occurred_at' => '2026-08-05']);
    debtEntry($customer, 'writeoff', -500, ['occurred_at' => '2026-08-09']);

    $debt = app(CustomerDebtService::class);

    expect($debt->history($customer->id)->last()['running'])
        ->toBe($debt->outstanding($customer->id));
});

// ─── Schema additions ───────────────────────────────────────────────────

test('a customer can hold an address, shop location and notes', function () {
    $customer = debtCustomer(debtVendor());

    $customer->update([
        'address'       => '12 Allen Avenue, Ikeja',
        'shop_location' => 'Phone shop opposite the bank',
        'notes'         => 'Pays weekly on Fridays.',
    ]);

    expect($customer->fresh()->shop_location)->toBe('Phone shop opposite the bank');
});

test('the cash ledger can now record which store the money moved through', function () {
    expect(Schema::hasColumn('financial_ledger_entries', 'store_id'))->toBeTrue();
});
