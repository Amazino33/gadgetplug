<?php

use App\Models\AuditSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAccountabilityEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\StockAccountabilityLedger;

// These rows say a named person is answerable for missing money, so the
// behaviour that matters most is what the ledger refuses to do.

function accountabilityContext(array $auditAttributes = []): array
{
    $owner = User::factory()->create();
    $staff = User::factory()->create();

    $vendor = Vendor::create(['user_id' => $owner->id, 'name' => 'Leisure Hub']);
    // vendor_users is membership only — the role column was dropped in
    // 2026_06_12_170956; roles live entirely in Spatie, scoped by team_id.
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

    $audit = AuditSession::create(array_merge([
        'vendor_id'        => $vendor->id,
        'product_id'       => $product->id,
        'system_quantity'  => 10,
        'storekeeper_a_id' => $staff->id,
        'count_a'          => 7,
        'storekeeper_b_id' => $owner->id,
        'count_b'          => 7,
        'status'           => 'verified',
    ], $auditAttributes));

    return compact('owner', 'staff', 'vendor', 'product', 'audit');
}

it('attributes a shortage against the frozen baseline, not live stock', function () {
    $c = accountabilityContext();

    // Stock moves after the count — a sale, a restock, anything. The attributed
    // variance must still be the one that was actually counted.
    $c['product']->update(['stock_quantity' => 2]);

    $entry = app(StockAccountabilityLedger::class)->attribute(
        audit: $c['audit'],
        disposition: 'recoverable',
        storekeeperId: $c['staff']->id,
        resolvedBy: $c['owner']->id,
    );

    expect($entry->quantity_variance)->toBe(-3)
        ->and((float) $entry->amount)->toBe(7710.0)
        ->and((float) $entry->unit_cost)->toBe(2570.0);
});

it('refuses to attribute a count that has no recorded baseline', function () {
    $c = accountabilityContext(['system_quantity' => null]);

    expect(fn () => app(StockAccountabilityLedger::class)->attribute(
        audit: $c['audit'],
        disposition: 'written_off',
        storekeeperId: $c['staff']->id,
        resolvedBy: $c['owner']->id,
    ))->toThrow(RuntimeException::class, 'no recorded system quantity');

    expect(StockAccountabilityEntry::count())->toBe(0);
});

it('refuses to attribute a count that is still in dispute', function () {
    $c = accountabilityContext(['status' => 'discrepancy', 'count_b' => 4]);

    expect(fn () => app(StockAccountabilityLedger::class)->attribute(
        audit: $c['audit'],
        disposition: 'recoverable',
        storekeeperId: $c['staff']->id,
        resolvedBy: $c['owner']->id,
    ))->toThrow(RuntimeException::class, 'not settled');
});

it('refuses to name someone who does not belong to the store', function () {
    $c = accountabilityContext();
    $outsider = User::factory()->create();

    expect(fn () => app(StockAccountabilityLedger::class)->attribute(
        audit: $c['audit'],
        disposition: 'recoverable',
        storekeeperId: $outsider->id,
        resolvedBy: $c['owner']->id,
    ))->toThrow(RuntimeException::class, 'not a member of this store');
});

it('never charges twice for the same count line', function () {
    $c = accountabilityContext();
    $ledger = app(StockAccountabilityLedger::class);

    $first  = $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);
    $second = $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    expect($second->id)->toBe($first->id)
        ->and(StockAccountabilityEntry::count())->toBe(1)
        ->and(app(StockAccountabilityLedger::class)->outstandingFor($c['staff']->id, $c['vendor']->id))
            ->toBe(7710.0);
});

it('is append-only — entries cannot be edited or deleted', function () {
    $c = accountabilityContext();
    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    expect(fn () => $entry->update(['amount' => 1]))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(LogicException::class, 'append-only');
});

it('cancels a debt with a reversal rather than an edit', function () {
    $c = accountabilityContext();
    $ledger = app(StockAccountabilityLedger::class);

    $entry = $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);
    expect($ledger->outstandingFor($c['staff']->id, $c['vendor']->id))->toBe(7710.0);

    $reversal = $ledger->reverse($entry, $c['owner']->id, 'Stock found in the back room.');

    expect($ledger->outstandingFor($c['staff']->id, $c['vendor']->id))->toBe(0.0)
        // The original is untouched — the trail shows both what was claimed and
        // that it was withdrawn.
        ->and($entry->fresh()->amount)->not->toBeNull()
        ->and($reversal->reverses_entry_id)->toBe($entry->id)
        ->and(StockAccountabilityEntry::count())->toBe(2);
});

it('keeps a written-off loss out of what staff owe', function () {
    $c = accountabilityContext();
    $ledger = app(StockAccountabilityLedger::class);

    $ledger->attribute($c['audit'], 'written_off', $c['staff']->id, $c['owner']->id);

    expect($ledger->outstandingFor($c['staff']->id, $c['vendor']->id))->toBe(0.0)
        ->and($ledger->writtenOffTotal($c['vendor']->id))->toBe(7710.0);
});

it('records without an amount when the disposition carries no money', function () {
    $c = accountabilityContext();

    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recorded', $c['staff']->id, $c['owner']->id);

    expect((float) $entry->amount)->toBe(0.0)
        ->and($entry->quantity_variance)->toBe(-3);
});

it('allows a loss to be recorded against the store with nobody named', function () {
    $c = accountabilityContext();

    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'written_off', null, $c['owner']->id);

    expect($entry->storekeeper_id)->toBeNull()
        ->and((float) $entry->amount)->toBe(7710.0);
});

it('records an overage as a positive variance rather than discarding it', function () {
    $c = accountabilityContext(['count_a' => 13, 'count_b' => 13]);

    $entry = app(StockAccountabilityLedger::class)
        ->attribute($c['audit'], 'recorded', $c['staff']->id, $c['owner']->id);

    expect($entry->quantity_variance)->toBe(3)
        ->and($entry->isShortage())->toBeFalse();
});

it('freezes unit cost so a later restock does not move what is owed', function () {
    $c = accountabilityContext();
    $ledger = app(StockAccountabilityLedger::class);

    $ledger->attribute($c['audit'], 'recoverable', $c['staff']->id, $c['owner']->id);

    // Restocked at a higher cost afterwards.
    $c['product']->update(['cost_price' => 4000]);

    expect($ledger->outstandingFor($c['staff']->id, $c['vendor']->id))->toBe(7710.0);
});
