<?php

use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinancialLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function ledgerVendorAccount(string $type = 'cash', float $openingBalance = 0.0): FinancialAccount
{
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Ledger Test Store ' . uniqid()]);

    $account = FinancialAccount::where('vendor_id', $vendor->id)->where('type', $type)->first();
    $account->update(['opening_balance' => $openingBalance]);

    return $account->fresh();
}

test('derived balance is correct after mixed in and out entries', function () {
    $account = ledgerVendorAccount(openingBalance: 1000);

    FinancialLedger::postEntry($account, 'in', 500, description: 'Sale');
    FinancialLedger::postEntry($account, 'out', 200, description: 'Refund');
    FinancialLedger::postEntry($account, 'in', 150, description: 'Sale');

    // 1000 + 500 + 150 - 200
    expect($account->balance())->toBe(1450.0);
});

test('posting the same source twice writes only one entry — idempotent', function () {
    $account = ledgerVendorAccount();
    $order = Order::create([
        'reference' => 'GP-' . uniqid(),
        'customer_name' => 'Idempotent Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo — Test',
        'total_amount' => 5000, 'status' => 'pending', 'payment_method' => 'pay_on_delivery',
    ]);

    $first = FinancialLedger::postEntry($account, 'in', 5000, source: $order, description: 'Order revenue');
    $second = FinancialLedger::postEntry($account, 'in', 5000, source: $order, description: 'Order revenue');

    expect($second->id)->toBe($first->id)
        ->and(\App\Models\FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->count())->toBe(1)
        ->and($account->balance())->toBe(5000.0);
});

test('a reversing entry restores the balance to what it was before the original post', function () {
    $account = ledgerVendorAccount(openingBalance: 2000);

    $entry = FinancialLedger::postEntry($account, 'in', 800, description: 'Sale posted in error');
    expect($account->balance())->toBe(2800.0);

    FinancialLedger::postEntry($account, 'out', 800, description: 'Reversal of mistaken sale');
    expect($account->balance())->toBe(2000.0);
});

test('balance as-of a date excludes later movements', function () {
    $account = ledgerVendorAccount(openingBalance: 0);

    FinancialLedger::postEntry($account, 'in', 1000, occurredAt: now()->subDays(5), description: 'Old sale');
    FinancialLedger::postEntry($account, 'in', 2000, occurredAt: now(), description: 'Recent sale');

    expect($account->balance(now()->subDays(3)))->toBe(1000.0)
        ->and($account->balance())->toBe(3000.0);
});

test('one vendor\'s ledger entries never leak into another vendor\'s balance', function () {
    $accountA = ledgerVendorAccount(openingBalance: 100);
    $accountB = ledgerVendorAccount(openingBalance: 100);

    FinancialLedger::postEntry($accountA, 'in', 5000, description: 'Vendor A revenue');

    expect($accountA->balance())->toBe(5100.0)
        ->and($accountB->balance())->toBe(100.0);
});

test('a ledger row cannot be updated', function () {
    $account = ledgerVendorAccount();
    $entry = FinancialLedger::postEntry($account, 'in', 100);

    expect(fn () => $entry->update(['amount' => 999]))
        ->toThrow(\LogicException::class);
});

test('a ledger row cannot be deleted', function () {
    $account = ledgerVendorAccount();
    $entry = FinancialLedger::postEntry($account, 'in', 100);

    expect(fn () => $entry->delete())
        ->toThrow(\LogicException::class);
});

test('a negative amount is rejected', function () {
    $account = ledgerVendorAccount();

    expect(fn () => FinancialLedger::postEntry($account, 'in', -50))
        ->toThrow(\InvalidArgumentException::class);
});

test('an invalid direction is rejected', function () {
    $account = ledgerVendorAccount();

    expect(fn () => FinancialLedger::postEntry($account, 'sideways', 50))
        ->toThrow(\InvalidArgumentException::class);
});

test('posting an entry is recorded in the activity log', function () {
    $account = ledgerVendorAccount();
    $entry = FinancialLedger::postEntry($account, 'in', 300, description: 'Logged sale');

    $activity = Activity::where('subject_type', \App\Models\FinancialLedgerEntry::class)
        ->where('subject_id', $entry->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('Ledger entry posted');
});

test('multiple source-less entries are all allowed, since NULL source is never a duplicate', function () {
    $account = ledgerVendorAccount();

    FinancialLedger::postEntry($account, 'in', 100, description: 'Manual adjustment 1');
    FinancialLedger::postEntry($account, 'in', 200, description: 'Manual adjustment 2');

    expect($account->balance())->toBe(300.0);
});

test('the same source can hold one out entry and one in entry without colliding', function () {
    // Reproduces the real scenario: an Order carries a delivery-cost 'out'
    // entry (Prompt 2) and, separately, a revenue-recognition 'in' entry
    // (Prompt 4) — both sourced from the same Order. Before widening the
    // uniqueness to include direction, the second post would have matched
    // the first entry and silently returned it instead of creating this one.
    $account = ledgerVendorAccount();
    $owner = User::factory()->create();
    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Collision Test Store']);
    $order = Order::create([
        'reference' => 'GP-' . uniqid(),
        'customer_name' => 'Collision Buyer', 'customer_email' => 'buyer@example.com',
        'customer_phone' => '08040000000', 'shipping_address' => 'Uyo — Test',
        'total_amount' => 5000, 'status' => 'delivered', 'payment_method' => 'pay_on_delivery',
    ]);

    $outEntry = FinancialLedger::postEntry($account, 'out', 1500, source: $order, description: 'Delivery cost');
    $inEntry  = FinancialLedger::postEntry($account, 'in', 5000, source: $order, description: 'Revenue recognized');

    expect($outEntry->id)->not->toBe($inEntry->id)
        ->and($outEntry->direction)->toBe('out')
        ->and($inEntry->direction)->toBe('in')
        ->and((float) $outEntry->amount)->toBe(1500.0)
        ->and((float) $inEntry->amount)->toBe(5000.0)
        ->and($account->balance())->toBe(3500.0);

    // Re-posting either direction for the same source still only writes once.
    $outAgain = FinancialLedger::postEntry($account, 'out', 1500, source: $order, description: 'Delivery cost retry');
    $inAgain  = FinancialLedger::postEntry($account, 'in', 5000, source: $order, description: 'Revenue retry');

    expect($outAgain->id)->toBe($outEntry->id)
        ->and($inAgain->id)->toBe($inEntry->id)
        ->and(\App\Models\FinancialLedgerEntry::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->count())->toBe(2);
});
