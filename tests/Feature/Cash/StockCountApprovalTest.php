<?php

use App\Actions\Inventory\ApproveStockCountAction;
use App\Actions\Inventory\RecordPhysicalCountAction;
use App\Models\AccountabilityLedgerEntry;
use App\Models\PhysicalStockCount;
use App\Models\ProductStoreStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Approving a count corrects the books. That is exactly why it is dangerous:
 * without these rules it would be the quietest way in the system to erase a
 * theft — no sale voided, no cash record touched, just a number brought into
 * line with an empty shelf.
 */
function countContext(int $onShelf = 10): array
{
    $ctx = handoffContext();

    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['keeper']->givePermissionTo('count_stock');
    $ctx['collector']->givePermissionTo('approve_stock_count');

    $ctx['product'] = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Counted Phone', 'price' => 100000, 'cost_price' => 60000,
        'stock_quantity' => $onShelf, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    ProductStoreStock::updateOrCreate(
        ['product_id' => $ctx['product']->id, 'store_id' => $ctx['store']->id],
        ['quantity' => $onShelf, 'reserved' => 0],
    );

    return $ctx;
}

function countOf(array $ctx, int $found): PhysicalStockCount
{
    return app(RecordPhysicalCountAction::class)->execute(
        countedBy:   $ctx['keeper'],
        store:       $ctx['store'],
        periodStart: now()->subWeek(),
        periodEnd:   now(),
        counts:      [$ctx['product']->id => $found],
    );
}

test('a count arrives waiting on somebody else to sign it off', function () {
    $ctx = countContext();

    expect(countOf($ctx, 7)->status)->toBe(PhysicalStockCount::STATUS_SUBMITTED);
});

test('the person who counted cannot approve their own count', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    // The rule the whole arrangement rests on.
    expect(fn () => app(ApproveStockCountAction::class)->approve(
        $count, $ctx['keeper'], PhysicalStockCount::OUTCOME_WRITTEN_OFF,
    ))->toThrow(RuntimeException::class, 'cannot approve a count you took yourself');

    expect($count->fresh()->status)->toBe(PhysicalStockCount::STATUS_SUBMITTED)
        ->and(ProductStoreStock::where('product_id', $ctx['product']->id)
            ->where('store_id', $ctx['store']->id)->value('quantity'))->toBe(10);
});

test('somebody without approval permission at that branch cannot sign it off', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    $passerby = User::factory()->create();

    expect(fn () => app(ApproveStockCountAction::class)->approve(
        $count, $passerby, PhysicalStockCount::OUTCOME_WRITTEN_OFF,
    ))->toThrow(RuntimeException::class, 'not permitted');
});

test('approving brings the books into line with the shelf', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    app(ApproveStockCountAction::class)->approve(
        $count, $ctx['collector'], PhysicalStockCount::OUTCOME_WRITTEN_OFF, note: 'Damaged in transit',
    );

    expect(ProductStoreStock::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)->value('quantity'))->toBe(7);

    $fresh = $count->fresh();

    expect($fresh->status)->toBe(PhysicalStockCount::STATUS_APPROVED)
        ->and($fresh->approved_by)->toBe($ctx['collector']->id)
        ->and($fresh->outcome)->toBe(PhysicalStockCount::OUTCOME_WRITTEN_OFF)
        ->and($fresh->adjusted_at)->not->toBeNull();
});

test('charging the shortage puts it on a named person, at the price the count froze', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    app(ApproveStockCountAction::class)->approve(
        $count, $ctx['collector'], PhysicalStockCount::OUTCOME_CHARGED,
        chargeTo: $ctx['keeper'], note: 'Unaccounted for',
    );

    // Charged at retail: what the business lost by not having it to sell.
    // 3 missing x 100,000.
    $owed = AccountabilityLedgerEntry::where('vendor_id', $ctx['vendor']->id)
        ->where('storekeeper_id', $ctx['keeper']->id)
        ->sum('amount');

    expect((float) $owed)->toBe(300000.0);

    // And it carries the branch, so a per-branch view of staff debt sees it.
    expect(AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)
        ->value('store_id'))->toBe($ctx['store']->id);
});

test('charging cannot happen without naming somebody', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    expect(fn () => app(ApproveStockCountAction::class)->approve(
        $count, $ctx['collector'], PhysicalStockCount::OUTCOME_CHARGED,
    ))->toThrow(RuntimeException::class, 'Name the person');
});

test('a retried approval does not charge the same person twice', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    app(ApproveStockCountAction::class)->approve(
        $count, $ctx['collector'], PhysicalStockCount::OUTCOME_CHARGED, chargeTo: $ctx['keeper'],
    );

    // Answering an already-answered count is refused outright, and the ledger
    // is idempotent per count per product besides.
    expect(fn () => app(ApproveStockCountAction::class)->approve(
        $count->fresh(), $ctx['collector'], PhysicalStockCount::OUTCOME_CHARGED, chargeTo: $ctx['keeper'],
    ))->toThrow(RuntimeException::class, 'already been answered');

    expect(AccountabilityLedgerEntry::where('storekeeper_id', $ctx['keeper']->id)->count())->toBe(1);
});

test('a sale between counting and approval is not silently reversed', function () {
    $ctx = countContext();

    // Counted 7 on a shelf the books said held 10.
    $count = countOf($ctx, 7);

    // Then two more genuinely sell before anybody approves.
    ProductStoreStock::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)
        ->decrement('quantity', 2);

    app(ApproveStockCountAction::class)->approve(
        $count, $ctx['collector'], PhysicalStockCount::OUTCOME_WRITTEN_OFF,
    );

    // 8 on the books, minus the 3 the count found missing, is 5. Setting stock
    // to the counted 7 instead would have handed back two units that really sold.
    expect(ProductStoreStock::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)->value('quantity'))->toBe(5);
});

test('rejecting a count keeps the figures and changes no stock', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    app(ApproveStockCountAction::class)->reject($count, $ctx['collector'], 'Count again with me present');

    $fresh = $count->fresh();

    expect($fresh->status)->toBe(PhysicalStockCount::STATUS_REJECTED)
        ->and($fresh->decision_note)->toBe('Count again with me present')
        ->and($fresh->lines->first()->counted_quantity)->toBe(7)
        ->and(ProductStoreStock::where('product_id', $ctx['product']->id)
            ->where('store_id', $ctx['store']->id)->value('quantity'))->toBe(10);
});

test('the counted figures can never be edited, even by an approver', function () {
    $ctx = countContext();
    $count = countOf($ctx, 7);

    // Approving decides what to do about the gap. It can never change what the
    // gap was.
    expect(fn () => $count->update(['counted_by' => $ctx['collector']->id]))
        ->toThrow(LogicException::class);

    expect(fn () => $count->lines->first()->update(['counted_quantity' => 10]))
        ->toThrow(LogicException::class);
});
