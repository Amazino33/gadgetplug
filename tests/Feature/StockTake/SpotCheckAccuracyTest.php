<?php

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Services\StockTake\StockTakeAccuracy;
use App\Services\StockTake\StockTakeCounter;
use App\Services\StockTake\StockTakeSpotCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

/**
 * Spot-checks: a random share of the counts that looked fine, counted again
 * by someone else. Accuracy: how often each counter's counts held up.
 */

/** 40 products on one aisle, all counted right by keeper A and handed in. */
function scCounted(): array
{
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $st    = stOpen($ctx);
    $items = collect(range(1, 40))->map(function ($i) use ($ctx, $aisle) {
        $p = stProduct($ctx, "Item {$i}", 10);
        stMap($ctx, $aisle, $p);

        return $p;
    });
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], $items->map(fn ($p) => [$aisle, $p, '10'])->all());

    return [$ctx, $aisle, $st, $task, $items];
}

test('a spot-check sends 5% of the counts that looked fine to someone else, as an ordinary recount', function () {
    [$ctx, $aisle, $st, $task] = scCounted();

    $tasks = app(StockTakeSpotCheck::class)->send($ctx['admin'], $st, $ctx['keeperB'], null);

    expect($tasks)->toHaveCount(1);
    $check = $tasks->first()->fresh();
    expect($check->type)->toBe(StockTakeTask::TYPE_RECOUNT)
        ->and($check->is_spot_check)->toBeTrue()
        ->and($check->assigned_to)->toBe($ctx['keeperB']->id)
        ->and($check->products()->count())->toBe(2); // 5% of 40

    expect(StockTakeResult::where('status', StockTakeResult::STATUS_SENT_TO_RECOUNT)->count())->toBe(2);
});

test('never to someone who counted them, and nothing to pick says so', function () {
    [$ctx, $aisle, $st] = scCounted();

    expect(fn () => app(StockTakeSpotCheck::class)->send($ctx['admin'], $st, $ctx['keeperA'], null))
        ->toThrow(ValidationException::class);
});

test('the size: at least one, never more than there are', function () {
    expect(StockTakeSpotCheck::sizeOf(0, 5))->toBe(0)
        ->and(StockTakeSpotCheck::sizeOf(3, 5))->toBe(1)
        ->and(StockTakeSpotCheck::sizeOf(40, 5))->toBe(2)
        ->and(StockTakeSpotCheck::sizeOf(10, 100))->toBe(10);
});

test('accuracy: a count that held, and one that did not, for the person who first counted them', function () {
    [$ctx, $aisle, $st, $task, $items] = scCounted();

    $tasks = app(StockTakeSpotCheck::class)->send($ctx['admin'], $st, $ctx['keeperB'], null, 5);
    $check = $tasks->first()->fresh();
    [$first, $second] = $check->products()->orderBy('products.id')->get()->all();

    // Keeper B finds the same for one, and 7 instead of 10 for the other.
    $counter = app(StockTakeCounter::class);
    foreach ([[$first, '10'], [$second, '7']] as [$p, $qty]) {
        $piece = ProductUnit::where('product_id', $p->id)->where('is_base', true)->value('id');
        $counter->save($ctx['keeperB'], $check->fresh(), $aisle, $p, [['unit_id' => $piece, 'qty' => $qty]], StockTakeCounter::MODE_NEW);
    }
    $counter->submit($ctx['keeperB'], $check->fresh(), []);

    $rows = app(StockTakeAccuracy::class)->forStockTake($st->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['name' => $ctx['keeperA']->name, 'checked' => 2, 'held' => 1, 'percent' => 50])
        ->and((float) $rows[0]['off_by'])->toBe(3.0);

    expect(app(StockTakeAccuracy::class)->forStore($ctx['vendor']->id, $ctx['store']->id))->toHaveCount(1);
});
