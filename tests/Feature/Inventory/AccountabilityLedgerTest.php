<?php

use App\Models\AccountabilityLedgerEntry;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountabilityLedger;
use App\Support\Accountability\FrozenLossSnapshot;

// A shortage is charged at retail — replacement cost plus the margin the store
// would have earned — and the split is frozen so the financial layer can book
// each half later without re-deriving anything from settings that have moved on.

function ledgerContext(array $productAttributes = []): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    $vendor->users()->attach($staff->id);

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $product = Product::create(array_merge([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 10,
        'status'         => 'published',
    ], $productAttributes));

    return compact('owner', 'staff', 'vendor', 'product');
}

// ── The split ────────────────────────────────────────────────────────────────

it('charges at retail and splits cost from margin', function () {
    $c = ledgerContext();

    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: 3,
        naturalKey: 'charge:case:1',
        storekeeperId: $c['staff']->id,
    );

    // 3 x 5,300 retail = 15,900, of which 3 x 2,570 = 7,710 is cost.
    expect((float) $entry->charge_amount)->toBe(15900.0)
        ->and((float) $entry->cost_component)->toBe(7710.0)
        ->and((float) $entry->margin_component)->toBe(8190.0)
        ->and((float) $entry->unit_cost_snapshot)->toBe(2570.0)
        ->and((float) $entry->unit_price_snapshot)->toBe(5300.0)
        ->and($entry->price_fallback)->toBeFalse()
        ->and($entry->shortage_qty)->toBe(3);
});

it('always reconciles: cost plus margin equals the charge', function () {
    // Awkward figures that do not divide cleanly, to catch rounding drift.
    $c = ledgerContext(['price' => 1999.99, 'cost_price' => 1333.33]);

    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: 7,
        naturalKey: 'charge:case:1',
    );

    expect((float) $entry->cost_component + (float) $entry->margin_component)
        ->toBe((float) $entry->charge_amount);
});

it('treats a negative shortage quantity as the magnitude of the loss', function () {
    $c = ledgerContext();

    // Variance arrives signed from the count; the charge is about how many are
    // missing, not the direction.
    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: -3,
        naturalKey: 'charge:case:1',
    );

    expect($entry->shortage_qty)->toBe(3)
        ->and((float) $entry->amount)->toBe(15900.0);
});

// ── Missing price ────────────────────────────────────────────────────────────

it('falls back to cost when the product has no retail price, without blocking', function () {
    $c = ledgerContext(['price' => 0]);

    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: 3,
        naturalKey: 'charge:case:1',
    );

    expect($entry->price_fallback)->toBeTrue()
        ->and((float) $entry->unit_price_snapshot)->toBe(2570.0)
        ->and((float) $entry->charge_amount)->toBe(7710.0)
        ->and((float) $entry->cost_component)->toBe(7710.0)
        // Charged at cost, so there is no margin to book.
        ->and((float) $entry->margin_component)->toBe(0.0);
});

it('still records a charge when neither price nor cost is known', function () {
    $c = ledgerContext(['price' => 0, 'cost_price' => null]);

    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: 3,
        naturalKey: 'charge:case:1',
    );

    // Nothing to charge, but the shortage is on the record rather than dropped.
    expect((float) $entry->charge_amount)->toBe(0.0)
        ->and($entry->price_fallback)->toBeTrue()
        ->and($entry->shortage_qty)->toBe(3);
});

// ── Immutability ─────────────────────────────────────────────────────────────

it('is append-only — entries cannot be updated or deleted', function () {
    $c = ledgerContext();

    $entry = app(AccountabilityLedger::class)->postCharge(
        vendorId: $c['vendor']->id,
        product: $c['product'],
        shortageQty: 3,
        naturalKey: 'charge:case:1',
    );

    expect(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only');
});

it('refuses an entry whose sign contradicts its type', function () {
    $c = ledgerContext();

    expect(fn () => AccountabilityLedgerEntry::create([
        'vendor_id'  => $c['vendor']->id,
        'entry_type' => 'charge',
        'amount'     => -100,
    ]))->toThrow(LogicException::class, 'cannot be negative');

    expect(fn () => AccountabilityLedgerEntry::create([
        'vendor_id'  => $c['vendor']->id,
        'entry_type' => 'recovery_cash',
        'amount'     => 100,
    ]))->toThrow(LogicException::class, 'cannot be positive');
});

it('rejects an unknown entry type', function () {
    $c = ledgerContext();

    expect(fn () => AccountabilityLedgerEntry::create([
        'vendor_id'  => $c['vendor']->id,
        'entry_type' => 'invented',
        'amount'     => 0,
    ]))->toThrow(LogicException::class, 'entry_type must be one of');
});

// ── Idempotency ──────────────────────────────────────────────────────────────

it('does not post the same charge twice', function () {
    $c = ledgerContext();
    $ledger = app(AccountabilityLedger::class);

    $first  = $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);
    $second = $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);

    expect($second->id)->toBe($first->id)
        ->and(AccountabilityLedgerEntry::count())->toBe(1)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
            ->toBe(15900.0);
});

it('does not post the same recovery twice', function () {
    $c = ledgerContext();
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);

    $ledger->postRecovery($c['vendor']->id, 'recovery_cash', 5000, 'recovery:evt:900', $c['staff']->id);
    $ledger->postRecovery($c['vendor']->id, 'recovery_cash', 5000, 'recovery:evt:900', $c['staff']->id);

    expect(AccountabilityLedgerEntry::where('entry_type', 'recovery_cash')->count())->toBe(1)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
            ->toBe(10900.0);
});

it('requires a natural key', function () {
    $c = ledgerContext();

    expect(fn () => app(AccountabilityLedger::class)
        ->postCharge($c['vendor']->id, $c['product'], 3, '   '))
        ->toThrow(InvalidArgumentException::class, 'natural key is required');
});

// ── Derived balances ─────────────────────────────────────────────────────────

it('nets charges against every kind of recovery', function () {
    $c = ledgerContext();
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);   // +15,900
    $ledger->postRecovery($c['vendor']->id, 'recovery_cash', 5000, 'r:1', $c['staff']->id);       //  −5,000
    $ledger->postRecovery($c['vendor']->id, 'recovery_salary', 4000, 'r:2', $c['staff']->id);     //  −4,000
    $ledger->postRecovery($c['vendor']->id, 'recovery_manual', 900, 'r:3', $c['staff']->id);      //    −900

    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
        ->toBe(6000.0);
});

it('stops a converted case showing as owed by the person', function () {
    $c = ledgerContext();
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id, caseId: 41);
    $ledger->postRecovery($c['vendor']->id, 'recovery_cash', 5900, 'r:1', $c['staff']->id, caseId: 41);

    expect(AccountabilityLedgerEntry::outstandingForCase(41, $c['vendor']->id))->toBe(10000.0);

    $conversion = $ledger->convertToWriteOff($c['vendor']->id, $c['staff']->id, 'writeoff:case:41', caseId: 41);

    expect((float) $conversion->amount)->toBe(-10000.0)
        ->and(AccountabilityLedgerEntry::outstandingForCase(41, $c['vendor']->id))->toBe(0.0)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))->toBe(0.0);
});

it('treats converting a settled balance as a no-op', function () {
    $c = ledgerContext();
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id, caseId: 41);
    $ledger->postRecovery($c['vendor']->id, 'recovery_cash', 15900, 'r:1', $c['staff']->id, caseId: 41);

    $conversion = $ledger->convertToWriteOff($c['vendor']->id, $c['staff']->id, 'writeoff:case:41', caseId: 41);

    expect($conversion)->toBeNull()
        ->and(AccountabilityLedgerEntry::outstandingForCase(41, $c['vendor']->id))->toBe(0.0);
});

it('keeps one storekeeper’s balance out of another’s', function () {
    $c = ledgerContext();
    $other = User::factory()->create();
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);
    $ledger->postCharge($c['vendor']->id, $c['product'], 1, 'charge:case:42', $other->id);

    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))->toBe(15900.0)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($other->id, $c['vendor']->id))->toBe(5300.0);
});

it('scopes balances to one store', function () {
    $c = ledgerContext();
    $otherVendor = Vendor::create(['user_id' => $c['owner']->id, 'name' => 'Second Store']);
    $ledger = app(AccountabilityLedger::class);

    $ledger->postCharge($c['vendor']->id, $c['product'], 3, 'charge:a', $c['staff']->id);
    $ledger->postCharge($otherVendor->id, $c['product'], 2, 'charge:b', $c['staff']->id);

    expect(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))->toBe(15900.0)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $otherVendor->id))->toBe(10600.0);
});

// ── Snapshot stays frozen ────────────────────────────────────────────────────

it('does not move when the product is repriced afterwards', function () {
    $c = ledgerContext();

    $entry = app(AccountabilityLedger::class)
        ->postCharge($c['vendor']->id, $c['product'], 3, 'charge:case:41', $c['staff']->id);

    $c['product']->update(['price' => 9000, 'cost_price' => 4000]);

    $fresh = $entry->fresh();

    expect((float) $fresh->unit_price_snapshot)->toBe(5300.0)
        ->and((float) $fresh->unit_cost_snapshot)->toBe(2570.0)
        ->and((float) $fresh->charge_amount)->toBe(15900.0)
        ->and(AccountabilityLedgerEntry::outstandingForStorekeeper($c['staff']->id, $c['vendor']->id))
            ->toBe(15900.0);
});

it('can rebuild a snapshot from frozen values without touching the product', function () {
    $snapshot = FrozenLossSnapshot::fromFrozen(
        shortageQty: 3,
        unitCostSnapshot: 2570.0,
        unitPriceSnapshot: 5300.0,
        priceFallback: false,
    );

    expect($snapshot->chargeAmount)->toBe(15900.0)
        ->and($snapshot->costComponent)->toBe(7710.0)
        ->and($snapshot->marginComponent)->toBe(8190.0);
});
