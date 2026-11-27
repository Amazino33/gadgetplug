<?php

use App\Models\InventoryLedger;
use App\Models\ProductUnit;
use App\Models\StockTakeEntry;
use App\Services\StockTake\StockTakeCounter;
use App\Services\StockTake\StockTakePack;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

/**
 * Damaged and expired items found while counting are kept apart from the good
 * count. They are on the shelf, so they are not missing; on approval they are
 * written off, so stock ends at the good count and the loss has its reason.
 */

function deCount(array $ctx, $task, $aisle, $product, string $good, float $damaged = 0, float $expired = 0): StockTakeEntry
{
    $piece = (int) ProductUnit::where('product_id', $product->id)->where('is_base', true)->value('id');

    return app(StockTakeCounter::class)->setFromPhone(
        $ctx['keeperA'], $task->fresh(), $aisle, $product, [['unit_id' => $piece, 'qty' => $good]],
        now(), now(), false, $damaged, $expired,
    );
}

test('50 on the books, 44 good + 4 damaged + 2 expired: no variance, and stock ends at 44 with 6 written off', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    deCount($ctx, $task, $aisle, $milk, '44', 4, 2);
    app(StockTakeCounter::class)->submit($ctx['keeperA'], $task->fresh(), []);

    $result = stResultFor($task, $milk);
    expect((float) $result->total_counted_base)->toBe(44.0)
        ->and((float) $result->damaged_qty_base)->toBe(4.0)
        ->and((float) $result->expired_qty_base)->toBe(2.0)
        ->and((float) $result->variance_qty)->toBe(0.0)
        ->and($result->needs_recount)->toBeFalse();

    $this->travel(1)->seconds();
    $approved = stApprover()->approve($ctx['admin'], $result);

    expect(stShelf($ctx, $milk))->toBe(44)
        ->and((float) $approved->applied_adjustment_qty)->toBe(0.0)
        ->and((float) $approved->writeoff_value)->toBe(6000.0);

    $writeoff = InventoryLedger::find($approved->writeoff_ledger_id);
    expect((float) $writeoff->quantity_change)->toBe(-6.0)
        ->and($writeoff->description)->toContain('4 damaged')->toContain('2 expired');
});

test('a real shortfall is still a shortfall: 50 on the books, 40 good + 5 damaged is 5 missing and 5 written off', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    deCount($ctx, $task, $aisle, $milk, '40', 5);
    app(StockTakeCounter::class)->submit($ctx['keeperA'], $task->fresh(), []);

    $result = stResultFor($task, $milk);
    expect((float) $result->variance_qty)->toBe(-5.0);

    $this->travel(1)->seconds();
    $approved = stApprover()->approve($ctx['admin'], $result);

    expect(stShelf($ctx, $milk))->toBe(40)
        ->and((float) $approved->applied_adjustment_qty)->toBe(-5.0);
});

test('nothing damaged: approval is as before, with no write-off', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();

    deCount($ctx, $task, $aisle, $milk, '50');
    app(StockTakeCounter::class)->submit($ctx['keeperA'], $task->fresh(), []);

    $this->travel(1)->seconds();
    $approved = stApprover()->approve($ctx['admin'], stResultFor($task, $milk));

    expect(stShelf($ctx, $milk))->toBe(50)
        ->and($approved->writeoff_ledger_id)->toBeNull()
        ->and($approved->writeoff_value)->toBeNull();
});

test('the phone sends damaged and expired, and gets them back in the pack', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $piece = (int) ProductUnit::where('product_id', $milk->id)->where('is_base', true)->value('id');

    $this->actingAs($ctx['keeperA'])->postJson("/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}/sync", ['device' => 'test-phone', 'ops' => [[
        'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'set', 'aisle_id' => $aisle->id, 'product_id' => $milk->id,
        'lines' => [['unit_id' => $piece, 'qty' => '10']], 'damaged' => 3, 'expired' => 1,
        'counted_at' => now()->toIso8601ZuluString(), 'device_counted_at' => now()->toIso8601ZuluString(),
    ]]])->assertOk()->assertJsonPath('results.0.status', 'synced');

    $entry = StockTakeEntry::sole();
    expect((float) $entry->damaged_qty_base)->toBe(3.0)->and((float) $entry->expired_qty_base)->toBe(1.0);

    $pack = app(StockTakePack::class)->build($task->fresh());
    expect(array_slice($pack['entries'][0], 7))->toBe([3.0, 1.0]);

    $this->postJson("/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}/sync", ['device' => 'test-phone', 'ops' => [[
        'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'set', 'aisle_id' => $aisle->id, 'product_id' => $milk->id,
        'lines' => [['unit_id' => $piece, 'qty' => '10']], 'damaged' => -2,
    ]]])->assertUnprocessable();
});
