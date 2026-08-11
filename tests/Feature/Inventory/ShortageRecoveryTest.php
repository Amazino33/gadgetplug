<?php

use App\Events\Accountability\ShortageCharged;
use App\Events\Accountability\ShortageRecovered;
use App\Events\Accountability\ShortageWrittenOff;
use App\Models\AccountabilityLedgerEntry;
use App\Models\AuditSession;
use App\Models\Category;
use App\Models\InventoryShortageCase;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Reporting\ShrinkageReadModel;
use App\Services\ShortageCaseService;
use Illuminate\Support\Facades\Event;

// Charge 15,900 = 7,710 cost + 8,190 margin, on three units at 5,300 retail
// against a 2,570 cost. Every figure below traces to that.

function recoveryContext(): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    $vendor->users()->attach($staff->id);

    $category = Category::firstOrCreate(['name' => 'Chargers']);

    $product = Product::create([
        'vendor_id'      => $vendor->id,
        'category_id'    => $category->id,
        'name'           => 'SHPLUS 60W Charger',
        'price'          => 5300,
        'cost_price'     => 2570,
        'stock_quantity' => 10,
        'status'         => 'published',
    ]);

    $line = AuditSession::create([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'system_quantity'  => 10,
        'storekeeper_a_id' => $staff->id,
        'count_a'          => 7,
        'storekeeper_b_id' => $owner->id,
        'count_b'          => 7,
        'status'           => 'verified',
    ]);

    $service = app(ShortageCaseService::class);
    $case    = $service->openForCountLine($line);
    $service->reassign($case, $staff->id);

    return compact('owner', 'staff', 'vendor', 'product', 'case', 'service');
}

function chargedCase(array $c): InventoryShortageCase
{
    $c['service']->charge($c['case'], $c['owner']->id, 'Confirmed missing.');

    return $c['case']->fresh();
}

// ── Partial recovery ─────────────────────────────────────────────────────────

it('sums partial recoveries toward the charge', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);
    $c['service']->recover($case->fresh(), 'recovery_salary', 4000, 'evt:2', $c['owner']->id);

    expect($c['service']->outstandingFor($case->fresh()))->toBe(6900.0)
        ->and($case->fresh()->status)->toBe('charged');
});

it('closes the case once nothing is outstanding', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 15900, 'evt:1', $c['owner']->id);

    expect($case->fresh()->status)->toBe('recovered')
        ->and($c['service']->outstandingFor($case->fresh()))->toBe(0.0);
});

it('blocks recovering more than is outstanding', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 15000, 'evt:1', $c['owner']->id);

    expect(fn () => $c['service']->recover($case->fresh(), 'recovery_cash', 1000, 'evt:2', $c['owner']->id))
        ->toThrow(RuntimeException::class, 'more than is outstanding');

    expect($c['service']->outstandingFor($case->fresh()))->toBe(900.0);
});

it('does not record the same recovery event twice', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);
    $c['service']->recover($case->fresh(), 'recovery_cash', 5000, 'evt:1', $c['owner']->id);

    expect(AccountabilityLedgerEntry::where('entry_type', 'recovery_cash')->count())->toBe(1)
        ->and($c['service']->outstandingFor($case->fresh()))->toBe(10900.0);
});

it('only allows recovery against a charged case', function () {
    $c = recoveryContext();

    // Still pending disposition.
    expect(fn () => $c['service']->recover($c['case'], 'recovery_cash', 100, 'evt:1', $c['owner']->id))
        ->toThrow(RuntimeException::class, 'Only a charged case');
});

// ── Cost-first allocation ────────────────────────────────────────────────────

it('allocates recovery to cost before margin', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);

    expect($c['service']->allocation($case->fresh()))
        ->toMatchArray([
            'recovered_cost'     => 5000.0,
            'recovered_margin'   => 0.0,
            'unrecovered_cost'   => 2710.0,
            'unrecovered_margin' => 8190.0,
        ]);
});

it('spills into margin only once cost is whole', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 10000, 'evt:1', $c['owner']->id);

    expect($c['service']->allocation($case->fresh()))
        ->toMatchArray([
            'recovered_cost'   => 7710.0,
            'recovered_margin' => 2290.0,
        ]);
});

// ── Conversion to write-off ──────────────────────────────────────────────────

it('moves only the unrecovered cost to loss and closes the case', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);
    $c['service']->convertToWriteOff($case->fresh(), 'wo:1', $c['owner']->id, 'Left the company.');

    $fresh = $case->fresh();
    $row   = app(ShrinkageReadModel::class)->forCase($fresh);

    expect($fresh->status)->toBe('written_off')
        ->and($c['service']->outstandingFor($fresh))->toBe(0.0)
        // 7,710 cost less 5,000 recovered. Not 7,710, and never the 10,900 retail remainder.
        ->and($row['shrinkage_loss_at_cost'])->toBe(2710.0)
        // Never earned, so never lost.
        ->and($row['margin_forgone'])->toBe(8190.0);
});

it('never counts unrecovered margin as income or expense', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->convertToWriteOff($case, 'wo:1', $c['owner']->id, 'Unrecoverable.');

    $totals = app(ShrinkageReadModel::class)->forVendor($c['vendor']->id);

    expect($totals['shrinkage_loss_at_cost'])->toBe(7710.0)
        ->and($totals['margin_forgone'])->toBe(8190.0)
        ->and($totals['recovered_margin'])->toBe(0.0)
        // Only the cost reaches the P&L.
        ->and($totals['net_shrinkage_at_cost'])->toBe(7710.0);
});

it('treats a written-off case with no charge as a full cost loss', function () {
    $c = recoveryContext();

    $c['service']->writeOff($c['case'], $c['owner']->id, 'Damaged in transit.');

    $totals = app(ShrinkageReadModel::class)->forVendor($c['vendor']->id);

    expect($totals['shrinkage_loss_at_cost'])->toBe(7710.0)
        ->and($totals['net_shrinkage_at_cost'])->toBe(7710.0);
});

it('recognises no loss while a charge is still being pursued', function () {
    $c = recoveryContext();
    chargedCase($c);

    $totals = app(ShrinkageReadModel::class)->forVendor($c['vendor']->id);

    // A receivable, not an expense.
    expect($totals['shrinkage_loss_at_cost'])->toBe(0.0)
        ->and($totals['outstanding_from_staff'])->toBe(15900.0);
});

it('reduces net shrinkage by everything recovered', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    $c['service']->recover($case, 'recovery_cash', 9000, 'evt:1', $c['owner']->id);
    $c['service']->convertToWriteOff($case->fresh(), 'wo:1', $c['owner']->id, 'Rest unrecoverable.');

    $totals = app(ShrinkageReadModel::class)->forVendor($c['vendor']->id);

    // Cost fully repaid (7,710) plus 1,290 of margin.
    expect($totals['recovered_cost'])->toBe(7710.0)
        ->and($totals['recovered_margin'])->toBe(1290.0)
        ->and($totals['shrinkage_loss_at_cost'])->toBe(0.0)
        // Recovered margin reduces the loss rather than being booked as income.
        ->and($totals['net_shrinkage_at_cost'])->toBe(-9000.0);
});

it('labels the direction of every P&L figure', function () {
    $c = recoveryContext();

    expect(app(ShrinkageReadModel::class)->forVendor($c['vendor']->id)['direction'])
        ->toBe([
            'shrinkage_loss_at_cost' => 'out',
            'recovered_cost'         => 'in',
            'recovered_margin'       => 'in',
        ]);
});

// ── Events ───────────────────────────────────────────────────────────────────

it('fires one event per write, with non-negative amounts', function () {
    Event::fake([ShortageCharged::class, ShortageRecovered::class, ShortageWrittenOff::class]);

    $c = recoveryContext();
    $case = chargedCase($c);

    Event::assertDispatchedTimes(ShortageCharged::class, 1);
    Event::assertDispatched(ShortageCharged::class, function (ShortageCharged $e) {
        return $e->chargedCost === 7710.0
            && $e->chargedMargin === 8190.0
            && $e->chargeTotal === 15900.0;
    });

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);

    Event::assertDispatchedTimes(ShortageRecovered::class, 1);
    Event::assertDispatched(ShortageRecovered::class, function (ShortageRecovered $e) {
        return $e->direction() === 'in'
            && $e->amount === 5000.0
            && $e->recoveredCost === 5000.0
            && $e->outstandingAfter === 10900.0;
    });

    $c['service']->convertToWriteOff($case->fresh(), 'wo:1', $c['owner']->id, 'Gone.');

    Event::assertDispatchedTimes(ShortageWrittenOff::class, 1);
    Event::assertDispatched(ShortageWrittenOff::class, function (ShortageWrittenOff $e) {
        return $e->direction() === 'out'
            && $e->lossAtCost === 2710.0
            && $e->origin === 'conversion';
    });
});

it('marks an outright write-off as a disposition, not a conversion', function () {
    Event::fake([ShortageWrittenOff::class]);

    $c = recoveryContext();
    $c['service']->writeOff($c['case'], $c['owner']->id, 'Damaged.');

    Event::assertDispatched(ShortageWrittenOff::class, function (ShortageWrittenOff $e) {
        return $e->origin === 'disposition' && $e->lossAtCost === 7710.0;
    });
});

it('does not fire a duplicate event for a repeated recovery key', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    Event::fake([ShortageRecovered::class]);

    $c['service']->recover($case, 'recovery_cash', 5000, 'evt:1', $c['owner']->id);
    $c['service']->recover($case->fresh(), 'recovery_cash', 5000, 'evt:1', $c['owner']->id);

    // The ledger collapses the duplicate, but the event fires per call — the
    // consumer must treat case_id + amount as the idempotent fact, which the
    // contract doc states.
    Event::assertDispatchedTimes(ShortageRecovered::class, 2);
    expect(AccountabilityLedgerEntry::where('entry_type', 'recovery_cash')->count())->toBe(1);
});

// ── Authorization on recovery ────────────────────────────────────────────────

it('lets only the owner record a recovery', function () {
    $c = recoveryContext();
    $case = chargedCase($c);

    expect($c['owner']->can('recordRecovery', $case))->toBeTrue()
        ->and($c['staff']->can('recordRecovery', $case))->toBeFalse();
});

it('blocks recording a recovery against your own debt', function () {
    $c = recoveryContext();

    // Reassign to the owner before charging, so the owner is the debtor.
    $c['service']->reassign($c['case'], $c['owner']->id);
    $c['service']->charge($c['case']->fresh(), $c['staff']->id, 'Charged.');

    expect($c['owner']->can('recordRecovery', $c['case']->fresh()))->toBeFalse();
});

it('offers no recovery on a case that is not charged', function () {
    $c = recoveryContext();

    // Still pending disposition.
    expect($c['owner']->can('recordRecovery', $c['case']))->toBeFalse();
});
