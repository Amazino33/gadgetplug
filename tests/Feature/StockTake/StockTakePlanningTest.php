<?php

use App\Models\AisleProduct;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\Store;
use App\Services\StockTake\StockTakePlanner;
use App\Services\StockTake\StockTakeScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Step 4: opening stock takes, handing out count tasks, the same-day
 * warning, progress, and decision 5 (a product counted in a handed-in task is
 * done for the stock take).
 */

test('a stock take opens as a draft and turns active with its first task', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $st    = stOpen($ctx, ['variance_threshold_percent' => 5, 'variance_threshold_value' => 2000]);

    expect($st->fresh()->status)->toBe(StockTake::STATUS_DRAFT)
        ->and($st->variance_threshold_percent)->toEqual(5.0)
        ->and($st->variance_threshold_value)->toEqual(2000.0);

    stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], '2026-11-25');

    expect($st->fresh()->status)->toBe(StockTake::STATUS_ACTIVE);
});

test('a second stock take at the branch is refused while one is open', function () {
    $ctx   = stockTakeContext();
    $first = stOpen($ctx);

    expect(fn () => stOpen($ctx, ['name' => 'Again']))
        ->toThrow(ValidationException::class, "{$first->reference} (November Full Count) is still open");
});

test('only someone who may run stock takes opens one or hands out tasks', function () {
    $ctx = stockTakeContext();

    expect(fn () => stPlanner()->open($ctx['keeperA'], $ctx['store'], ['name' => 'X', 'start_date' => '2026-11-24', 'end_date' => '2026-11-25']))
        ->toThrow(AuthorizationException::class);

    $st = stOpen($ctx);

    expect(fn () => stPlanner()->assignCount($ctx['keeperA'], $st, [stAisle($ctx, 'A')->id], $ctx['keeperB'], null))
        ->toThrow(AuthorizationException::class);
});

test('a count task goes only to someone who may count at the branch', function () {
    $ctx = stockTakeContext();
    $st  = stOpen($ctx);

    // The store admin holds manage, not perform.
    expect(fn () => stPlanner()->assignCount($ctx['admin'], $st, [stAisle($ctx, 'A')->id], $ctx['admin'], null))
        ->toThrow(ValidationException::class, 'cannot count at this branch');
});

test('an aisle already in an open task is refused, naming who has it', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $st    = stOpen($ctx);

    stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);

    expect(fn () => stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperB'], null))
        ->toThrow(ValidationException::class, "Aisle 1 is already in {$ctx['keeperA']->name}'s task");
});

test('aisles of another branch or out of use cannot be put on a task', function () {
    $ctx    = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);
    $st     = stOpen($ctx);
    $off    = stAisle($ctx, 'Old');
    $off->update(['is_active' => false]);

    expect(fn () => stPlanner()->assignCount($ctx['admin'], $st, [stAisle($ctx, 'L', $branch)->id], $ctx['keeperA'], null))
        ->toThrow(ValidationException::class, 'Pick aisles in use');
    expect(fn () => stPlanner()->assignCount($ctx['admin'], $st, [$off->id], $ctx['keeperA'], null))
        ->toThrow(ValidationException::class, 'Pick aisles in use');
});

test('the same-day warning names products that also sit on an aisle not chosen', function () {
    $ctx   = stockTakeContext();
    $a2    = stAisle($ctx, 'Aisle 2');
    $room  = stAisle($ctx, 'Store Room');
    $a3    = stAisle($ctx, 'Aisle 3');
    $milk  = stProduct($ctx, 'Peak Milk 400g');
    $sugar = stProduct($ctx, 'Dangote Sugar');
    stMap($ctx, $a2, $milk, $sugar);
    stMap($ctx, $room, $milk);
    stMap($ctx, $a3, $sugar);
    $st = stOpen($ctx);

    // Blessing has Aisle 3 already.
    stPlanner()->assignCount($ctx['admin'], $st, [$a3->id], $ctx['keeperA'], null);

    $warnings = collect(StockTakeScope::sameDayWarnings($st, [$a2->id]))->keyBy('product_id');

    expect($warnings)->toHaveCount(2)
        ->and($warnings[$milk->id]['elsewhere'][0]['label'])->toBe('Store Room')
        ->and($warnings[$milk->id]['elsewhere'][0]['held_by'])->toBeNull()
        ->and($warnings[$sugar->id]['elsewhere'][0]['label'])->toBe('Aisle 3')
        ->and($warnings[$sugar->id]['elsewhere'][0]['held_by'])->toBe($ctx['keeperA']->name);

    // Choosing both places together clears the warning for the milk.
    expect(collect(StockTakeScope::sameDayWarnings($st, [$a2->id, $room->id]))->pluck('product_id')->all())
        ->toBe([$sugar->id]);
});

test('decision 5: a product counted in a handed-in task drops out of later tasks and the warning', function () {
    $ctx   = stockTakeContext();
    $a2    = stAisle($ctx, 'Aisle 2');
    $room  = stAisle($ctx, 'Store Room');
    $milk  = stProduct($ctx, 'Peak Milk 400g');
    $rice  = stProduct($ctx, 'Rice 5kg');
    stMap($ctx, $a2, $milk);
    stMap($ctx, $room, $milk, $rice);
    $st = stOpen($ctx);

    // Tuesday: Aisle 2 counted and handed in, milk included.
    stHandIn($ctx, $st, $a2, [$milk]);

    // Thursday: a task for the store room expects only the rice.
    $thursday = stPlanner()->assignCount($ctx['admin'], $st, [$room->id], $ctx['keeperB'], '2026-11-26');

    expect(StockTakeScope::expectedFor($thursday)->all())->toBe([$rice->id])
        ->and(StockTakeScope::sameDayWarnings($st, [$room->id]))->toBe([])
        ->and(StockTakeScope::countedWhere($st, $milk->id)['aisles'])->toBe(['Aisle 2']);
});

test('a task still with its counter does not make a product done', function () {
    $ctx   = stockTakeContext();
    $a2    = stAisle($ctx, 'Aisle 2');
    $milk  = stProduct($ctx);
    stMap($ctx, $a2, $milk);
    $st   = stOpen($ctx);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$a2->id], $ctx['keeperA'], null);

    StockTakeEntry::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'stock_take_task_id' => $task->id, 'aisle_id' => $a2->id, 'product_id' => $milk->id,
        'counted_by' => $ctx['keeperA']->id, 'counted_qty_base' => 5, 'counted_at' => now(),
    ]);

    expect(StockTakeScope::done($st))->toHaveCount(0)
        ->and(StockTakeScope::uncounted($st)->all())->toBe([$milk->id]);
});

test('progress counts done products as counted, never as uncounted', function () {
    $ctx  = stockTakeContext();
    $a1   = stAisle($ctx, 'Aisle 1');
    $a2   = stAisle($ctx, 'Aisle 2');
    $off  = stAisle($ctx, 'Old shelf');
    [$p1, $p2, $p3, $p4] = [stProduct($ctx, 'One'), stProduct($ctx, 'Two'), stProduct($ctx, 'Three'), stProduct($ctx, 'Four')];
    stMap($ctx, $a1, $p1, $p2);
    stMap($ctx, $a2, $p3);
    stMap($ctx, $off, $p4);
    $off->update(['is_active' => false]);
    $st = stOpen($ctx);

    stHandIn($ctx, $st, $a1, [$p1, $p2]);

    expect(StockTakeScope::progress($st))->toMatchArray([
        'in_scope'  => 3,
        'counted'   => 2,
        'approved'  => 0,
        'remaining' => 1,
        'percent'   => 66,
    ])->and(StockTakeScope::uncounted($st)->all())->toBe([$p3->id]);
});

test('products with stock on no active aisle are listed as not mapped', function () {
    $ctx      = stockTakeContext();
    $aisle    = stAisle($ctx, 'Aisle 1');
    $mapped   = stProduct($ctx, 'Mapped', 10);
    $loose    = stProduct($ctx, 'Loose', 7);
    $empty    = stProduct($ctx, 'Sold out', 0);
    $service  = stProduct($ctx, 'Delivery fee', 5);
    $service->update(['is_service' => true]);
    stMap($ctx, $aisle, $mapped);

    $ids = StockTakeScope::notMappedQuery($ctx['vendor']->id, $ctx['store']->id)->pluck('products.id')->all();

    expect($ids)->toBe([$loose->id]);
});

test('closing waits for counters to hand in, then a carry-over counts only what was left', function () {
    $ctx  = stockTakeContext();
    $a1   = stAisle($ctx, 'Aisle 1');
    $a2   = stAisle($ctx, 'Aisle 2');
    [$p1, $p2] = [stProduct($ctx, 'One'), stProduct($ctx, 'Two')];
    stMap($ctx, $a1, $p1);
    stMap($ctx, $a2, $p2);
    $st = stOpen($ctx);

    stHandIn($ctx, $st, $a1, [$p1]);
    $open = stPlanner()->assignCount($ctx['admin'], $st, [$a2->id], $ctx['keeperB'], null);

    expect(fn () => stPlanner()->close($ctx['admin'], $st))->toThrow(ValidationException::class, 'Still with counters');

    stPlanner()->cancelTask($ctx['admin'], $open);
    stPlanner()->close($ctx['admin'], $st->fresh());

    expect($st->fresh()->status)->toBe(StockTake::STATUS_CLOSED)
        ->and($st->fresh()->closed_by)->toBe($ctx['admin']->id);

    $next = stPlanner()->carryOver($ctx['admin'], $st->fresh(), [
        'name' => 'November leftovers', 'start_date' => '2026-12-01', 'end_date' => '2026-12-02',
    ]);

    expect($next->carried_from_id)->toBe($st->id)
        ->and(StockTakeScope::inScope($next)->all())->toBe([$p2->id])
        ->and(StockTakeScope::progress($next)['in_scope'])->toBe(1);
});

test('an unknown barcode linked to a product gives it the barcode and its aisle', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Aisle 4');
    $product = stProduct($ctx, 'Spaghetti');
    $item    = StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'aisle_id' => $aisle->id,
        'barcode' => '9990001112223', 'name_entered' => 'Golden Penny Spaghetti', 'created_by' => $ctx['keeperA']->id,
    ]);

    stPlanner()->linkUnknown($ctx['admin'], $item, $product);

    expect($product->fresh()->barcode)->toBe('9990001112223')
        ->and($item->fresh()->status)->toBe('resolved')
        ->and($item->fresh()->resolved_product_id)->toBe($product->id)
        ->and($aisle->products()->whereKey($product->id)->exists())->toBeTrue();
});

test('an unknown barcode can become a new product, or be ignored', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 4');
    $make  = fn ($code) => StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'aisle_id' => $aisle->id,
        'barcode' => $code, 'name_entered' => 'Mystery', 'created_by' => $ctx['keeperA']->id,
    ]);

    $created = stPlanner()->createFromUnknown($ctx['admin'], $make('5550001'), ['name' => 'Mystery Biscuit', 'price' => 300]);

    expect($created->barcode)->toBe('5550001')
        ->and($created->vendor_id)->toBe($ctx['vendor']->id)
        ->and($aisle->products()->whereKey($created->id)->exists())->toBeTrue();

    $ignored = $make('5550002');
    stPlanner()->ignoreUnknown($ctx['admin'], $ignored);

    expect($ignored->fresh()->status)->toBe('ignored');
    expect(fn () => stPlanner()->ignoreUnknown($ctx['admin'], $ignored->fresh()))->toThrow(ValidationException::class);
});
