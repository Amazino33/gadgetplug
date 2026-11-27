<?php

use App\Models\AisleProduct;
use App\Models\AuditSession;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Step 2: the tables, the relationships, and the rules the database itself
 * enforces, so that later steps cannot get them wrong by racing.
 */

test('a branch has one open stock take at a time, and closing one frees the branch', function () {
    $ctx   = stockTakeContext();
    $first = stStockTake($ctx);

    expect($first->fresh()->status)->toBe(StockTake::STATUS_DRAFT)
        ->and($first->reference)->toBe('ST-0001');

    expect(fn () => stStockTake($ctx, ['name' => 'Second']))->toThrow(QueryException::class);

    $first->update(['status' => StockTake::STATUS_CLOSED, 'closed_by' => $ctx['admin']->id, 'closed_at' => now()]);

    $second = stStockTake($ctx, ['name' => 'December Full Count']);

    expect($second->reference)->toBe('ST-0002')
        ->and($first->fresh()->open_key)->toBeNull()
        ->and($second->open_key)->toBe((string) $ctx['store']->id);
});

test('another branch of the same vendor can run its own stock take at the same time', function () {
    $ctx    = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);

    stStockTake($ctx);
    $other = stStockTake($ctx, ['store_id' => $branch->id]);

    expect($other->exists)->toBeTrue();
});

test('a product can sit in several aisles, but is mapped to one aisle once', function () {
    $ctx     = stockTakeContext();
    $shelf   = stAisle($ctx, 'Aisle 2');
    $back    = stAisle($ctx, 'Store Room');
    $product = stProduct($ctx);

    $pivot = [
        'vendor_id' => $ctx['vendor']->id,
        'source'    => AisleProduct::SOURCE_MAPPING,
        'mapped_by' => $ctx['admin']->id,
        'mapped_at' => now(),
    ];
    $shelf->products()->attach($product->id, $pivot);
    $back->products()->attach($product->id, $pivot);

    expect($product->aisles()->pluck('name')->sort()->values()->all())->toBe(['Aisle 2', 'Store Room'])
        ->and($shelf->products()->first()->pivot->source)->toBe('mapping');

    expect(fn () => $shelf->products()->attach($product->id, $pivot))->toThrow(QueryException::class);
});

test('an aisle cannot be in two open count tasks of the same stock take', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $aisle     = stAisle($ctx, 'Aisle 1');

    stTask($ctx, $stockTake, $ctx['keeperA'])->attachAisles([$aisle->id]);

    expect(fn () => stTask($ctx, $stockTake, $ctx['keeperB'])->attachAisles([$aisle->id]))
        ->toThrow(QueryException::class);
});

test('a task that stops being open lets go of its aisles', function (string $status) {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $aisle     = stAisle($ctx, 'Aisle 1');

    $first = stTask($ctx, $stockTake, $ctx['keeperA']);
    $first->attachAisles([$aisle->id]);
    $first->update(['status' => $status]);

    stTask($ctx, $stockTake, $ctx['keeperB'])->attachAisles([$aisle->id]);

    expect($first->aisles()->first()->pivot->lock_key)->toBeNull()
        ->and($first->aisles()->count())->toBe(1);
})->with([StockTakeTask::STATUS_SUBMITTED, StockTakeTask::STATUS_CANCELLED]);

test('mapping tasks do not hold an aisle', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $aisle     = stAisle($ctx, 'Aisle 1');

    stTask($ctx, $stockTake, $ctx['keeperA'])->attachAisles([$aisle->id]);
    stTask($ctx, $stockTake, $ctx['keeperB'], StockTakeTask::TYPE_MAPPING)->attachAisles([$aisle->id]);

    // A mapping task outside any stock take.
    stTask($ctx, $stockTake, $ctx['keeperB'], StockTakeTask::TYPE_MAPPING, ['stock_take_id' => null])
        ->attachAisles([$aisle->id]);

    expect($aisle->tasks()->count())->toBe(3)
        ->and($aisle->tasks()->whereNotNull('stock_take_task_aisles.lock_key')->count())->toBe(1);
});

test('one entry per product per aisle per task, holding no system quantity', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $aisle     = stAisle($ctx, 'Aisle 1');
    $product   = stProduct($ctx);
    $task      = stTask($ctx, $stockTake, $ctx['keeperA']);

    $entry = fn ($qty) => StockTakeEntry::create([
        'vendor_id'          => $ctx['vendor']->id,
        'store_id'           => $ctx['store']->id,
        'stock_take_task_id' => $task->id,
        'aisle_id'           => $aisle->id,
        'product_id'         => $product->id,
        'counted_by'         => $ctx['keeperA']->id,
        'counted_qty_base'   => $qty,
        'entry_breakdown'    => [['unit_id' => null, 'unit' => 'Piece', 'factor' => 1, 'qty' => $qty]],
        'counted_at'         => now(),
    ]);

    $saved = $entry(12.5)->fresh();
    $keys  = array_keys($saved->toArray());

    expect($saved->counted_qty_base)->toBe(12.5)
        ->and($saved->entry_breakdown[0]['unit'])->toBe('Piece')
        ->and(array_values(array_filter($keys, fn ($key) => str_contains($key, 'system'))))->toBe([]);

    expect(fn () => $entry(3))->toThrow(QueryException::class);
});

test('a result keeps what was found; only the decision about it can change', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $product   = stProduct($ctx);
    $task      = stTask($ctx, $stockTake, $ctx['keeperA'], over: ['status' => StockTakeTask::STATUS_SUBMITTED]);

    $result = StockTakeResult::create([
        'vendor_id'           => $ctx['vendor']->id,
        'store_id'            => $ctx['store']->id,
        'stock_take_id'       => $stockTake->id,
        'stock_take_task_id'  => $task->id,
        'product_id'          => $product->id,
        'total_counted_base'  => 48,
        'counted_at'          => now(),
        'system_qty_at_count' => 50,
        'variance_qty'        => -2,
        'unit_cost'           => 1000,
        'variance_value'      => -2000,
    ]);

    $result->update([
        'status'                 => StockTakeResult::STATUS_APPROVED,
        'approved_by'            => $ctx['admin']->id,
        'approved_at'            => now(),
        'applied_adjustment_qty' => -2,
    ]);

    expect($result->fresh()->status)->toBe('approved')
        ->and($result->fresh()->variance_qty)->toBe(-2);

    expect(fn () => $result->update(['total_counted_base' => 50]))->toThrow(LogicException::class);
    expect(fn () => $result->fresh()->update(['variance_qty' => 0]))->toThrow(LogicException::class);
});

test('a recount and the count it re-checks are linked both ways', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $product   = stProduct($ctx);
    $original  = stTask($ctx, $stockTake, $ctx['keeperA'], over: ['status' => StockTakeTask::STATUS_SUBMITTED]);
    $recount   = stTask($ctx, $stockTake, $ctx['keeperB'], StockTakeTask::TYPE_RECOUNT, ['parent_task_id' => $original->id]);
    $recount->products()->attach($product->id);

    $make = fn ($task, $counted, $of = null) => StockTakeResult::create([
        'vendor_id'           => $ctx['vendor']->id,
        'store_id'            => $ctx['store']->id,
        'stock_take_id'       => $stockTake->id,
        'stock_take_task_id'  => $task->id,
        'product_id'          => $product->id,
        'total_counted_base'  => $counted,
        'counted_at'          => now(),
        'system_qty_at_count' => 50,
        'variance_qty'        => $counted - 50,
        'recount_of_id'       => $of,
    ]);

    $first = $make($original, 30);
    $first->update(['status' => StockTakeResult::STATUS_SENT_TO_RECOUNT, 'recount_task_id' => $recount->id]);
    $second = $make($recount, 49, $first->id);

    expect($recount->parent->is($original))->toBeTrue()
        ->and($original->recounts->first()->is($recount))->toBeTrue()
        ->and($recount->products->first()->is($product))->toBeTrue()
        ->and($first->fresh()->recountResult->is($second))->toBeTrue()
        ->and($second->recountOf->is($first))->toBeTrue()
        ->and($first->fresh()->recountTask->is($recount))->toBeTrue();
});

test('an audit line can say which stock take task it came from', function () {
    $ctx       = stockTakeContext();
    $stockTake = stStockTake($ctx);
    $product   = stProduct($ctx);
    $task      = stTask($ctx, $stockTake, $ctx['keeperA']);

    $line = AuditSession::create([
        'vendor_id'          => $ctx['vendor']->id,
        'stock_take_task_id' => $task->id,
        'product_id'         => $product->id,
        'system_quantity'    => 50,
        'storekeeper_a_id'   => $ctx['keeperA']->id,
        'count_a'            => 48,
        'status'             => 'verified',
    ]);

    expect($line->fresh()->stockTakeTask->is($task))->toBeTrue();
});

test('the vendor and branch reach their aisles and stock takes', function () {
    $ctx = stockTakeContext();
    stAisle($ctx, 'Aisle 1');
    stStockTake($ctx);

    expect($ctx['vendor']->aisles()->count())->toBe(1)
        ->and($ctx['vendor']->stockTakes()->count())->toBe(1)
        ->and($ctx['store']->aisles()->count())->toBe(1)
        ->and($ctx['store']->stockTakes()->count())->toBe(1);
});
