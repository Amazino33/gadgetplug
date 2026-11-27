<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Models\ProductBarcode;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\StockTakeEntry;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Services\StockTake\StockTakeApprover;
use App\Services\StockTake\StockTakeCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/**
 * Release 2, Part A1–A4: the task pack, and batches of what the phone counted
 * offline.
 */

function stOffline(array $ctx, StockTakeTask $task, string $path = ''): string
{
    // Every download says which phone it is (one phone per task, A7).
    $query = $path === '/pack' ? '?device=test-phone' : '';

    return "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}{$path}{$query}";
}

/** One "set" operation as the phone sends it. */
function stSetOp(int $aisleId, int $productId, array $lines, ?string $countedAt = null, ?string $id = null): array
{
    return [
        'id'                => $id ?? (string) Str::uuid(),
        'type'              => 'set',
        'aisle_id'          => $aisleId,
        'product_id'        => $productId,
        'lines'             => $lines,
        'counted_at'        => $countedAt ?? now()->toIso8601ZuluString(),
        'device_counted_at' => $countedAt ?? now()->toIso8601ZuluString(),
    ];
}

function stPieceOf($product): int
{
    return (int) ProductUnit::where('product_id', $product->id)->where('is_base', true)->value('id');
}

test('the pack holds every product with its barcodes mapped to units, and the aisles in mapping order', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $coke = stProduct($ctx, 'Coke');
    $coke->update(['barcode' => '544900']);
    $crate = ProductUnit::create(['product_id' => $coke->id, 'vendor_id' => $ctx['vendor']->id, 'name' => 'Crate', 'conversion_factor' => 12, 'price' => 1, 'is_base' => false, 'is_active' => true]);
    ProductBarcode::create(['vendor_id' => $ctx['vendor']->id, 'product_id' => $coke->id, 'product_unit_id' => $crate->id, 'barcode' => '544912']);
    $elsewhere = stProduct($ctx, 'Not on any aisle');
    $aisle = stAisle($ctx, 'Aisle 2');
    stMap($ctx, $aisle, $coke);
    Carbon::setTestNow(now()->addMinute());
    stMap($ctx, $aisle, $milk);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $pack = $this->actingAs($ctx['keeperA'])->getJson(stOffline($ctx, $task, '/pack'))->assertOk()->json();

    $products = collect($pack['products'])->keyBy(0);

    expect($pack['task']['aisles'][0]['expected'])->toBe([$coke->id, $milk->id])
        ->and($products)->toHaveKey($elsewhere->id)
        ->and($products[$coke->id][1])->toStartWith('Coke')
        ->and($products[$coke->id][5][0])->toBe([$crate->id, 'Crate', 12])
        ->and($products[$coke->id][6])->toContain(['544900', null], ['544912', $crate->id])
        ->and($pack['server_time'])->not->toBeNull()
        ->and($pack['task']['allow_halves'])->toBeFalse();
});

test('the pack is blind: no stock, cost or price anywhere in it', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk', 4817);
    $milk->update(['cost_price' => 3911, 'price' => 6733]);
    $aisle = stAisle($ctx, 'Aisle 2');
    stMap($ctx, $aisle, $milk);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $body = $this->actingAs($ctx['keeperA'])->getJson(stOffline($ctx, $task, '/pack'))->json();
    $json = json_encode($body);

    expect($json)->not->toContain('4817')->not->toContain('3911')->not->toContain('6733')
        ->not->toContain('"stock"')->not->toContain('stock_quantity')->not->toContain('quantity')
        ->not->toContain('price')->not->toContain('cost')->not->toContain('system');

    // And the sync response.
    $sync = $this->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '3']])]])->json();

    expect(json_encode($sync))->not->toContain('4817')->not->toContain('quantity')->not->toContain('price')->not->toContain('system');
});

test('a task already part-counted the old way carries its counts into the pack', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $aisle = stAisle($ctx, 'Aisle 2');
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    app(StockTakeCounter::class)->save($ctx['keeperA'], $task, $aisle, $milk, [['unit_id' => stPieceOf($milk), 'qty' => '12']]);

    $pack = $this->actingAs($ctx['keeperA'])->getJson(stOffline($ctx, $task, '/pack'))->json();

    expect($pack['entries'])->toHaveCount(1)
        ->and($pack['entries'][0][0])->toBe($aisle->id)
        ->and($pack['entries'][0][1])->toBe($milk->id)
        ->and($pack['entries'][0][2])->toBe(12)
        ->and($pack['entries'][0][3][0]['qty'])->toBe(12);
});

test('the pack names what is already counted in a handed-in task, with day and aisles', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    stMap($ctx, $a2, $milk);
    $st = stOpen($ctx);
    Carbon::setTestNow('2026-11-24 10:00:00');
    stHandIn($ctx, $st, $a2, [$milk], '2026-11-24 10:00:00');
    $later = stPlanner()->assignCount($ctx['admin'], $st, [$room->id], $ctx['keeperB'], null);

    $pack = $this->actingAs($ctx['keeperB'])->getJson(stOffline($ctx, $later, '/pack'))->json();

    expect($pack['done'][$milk->id])->toBe(['Tuesday 24 Nov', ['Aisle 2']]);
});

test('only the assignee downloads the pack; another shop never', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [stAisle($ctx, 'A')->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperB'])->getJson(stOffline($ctx, $task, '/pack'))->assertForbidden();
    $this->actingAs($other['keeperA'])->getJson(stOffline($ctx, $task, '/pack'))->assertForbidden();
});

test('a batch is applied in order: set, add as an absolute, take back', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $sugar = stProduct($ctx, 'Sugar');
    $aisle = stAisle($ctx, 'Aisle 2');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $p = stPieceOf($milk);

    $results = $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [
        stSetOp($aisle->id, $milk->id, [['unit_id' => $p, 'qty' => '12']]),
        // The phone added 5 and sends what the entry now is: 17.
        stSetOp($aisle->id, $milk->id, [['unit_id' => $p, 'qty' => '17']]),
        stSetOp($aisle->id, $sugar->id, [['unit_id' => stPieceOf($sugar), 'qty' => '4']]),
        ['id' => (string) Str::uuid(), 'type' => 'remove', 'aisle_id' => $aisle->id, 'product_id' => $sugar->id],
    ]])->assertOk()->json('results');

    expect(collect($results)->pluck('status')->unique()->all())->toBe(['synced'])
        ->and(StockTakeEntry::sole()->product_id)->toBe($milk->id)
        ->and(StockTakeEntry::sole()->counted_qty_base)->toBe(17)
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_IN_PROGRESS);
});

test('a batch whose answer was lost, sent again, changes nothing and gets the same answers', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $aisle = stAisle($ctx, 'Aisle 2');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $ops = [
        stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '12']]),
        stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '1.5']]), // refused: no halves
    ];

    $first  = $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => $ops])->json('results');
    $second = $this->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => $ops])->json('results');

    expect($second)->toBe($first)
        ->and($first[0]['status'])->toBe('synced')
        ->and($first[1]['status'])->toBe('rejected')
        ->and($first[1]['reason'])->toContain('whole units')
        ->and(StockTakeEntry::sole()->counted_qty_base)->toBe(12)
        ->and(DB::table('stock_take_sync_ops')->count())->toBe(2);
});

test('decision 5 on upload: a product handed in elsewhere after the pack was downloaded is refused', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    $st   = stOpen($ctx);
    $mine = stPlanner()->assignCount($ctx['admin'], $st, [$room->id], $ctx['keeperB'], null);

    // Meanwhile another task counts the milk and hands in.
    stHandIn($ctx, $st, $a2, [$milk]);

    $result = $this->actingAs($ctx['keeperB'])->postJson(stOffline($ctx, $mine, '/sync'), ['device' => 'test-phone', 'ops' => [
        stSetOp($room->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '30']]),
    ]])->json('results.0');

    expect($result['status'])->toBe('rejected')
        ->and($result['reason'])->toContain('Already counted')
        ->and(StockTakeEntry::where('stock_take_task_id', $mine->id)->count())->toBe(0);
});

test('a task cancelled while the phone was offline: its operations come back refused, with why', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $aisle = stAisle($ctx, 'Aisle 2');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    stPlanner()->cancelTask($ctx['admin'], $task);

    $result = $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [
        stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '3']]),
    ]])->assertOk()->json('results.0');

    expect($result['status'])->toBe('rejected')
        ->and($result['reason'])->toContain('no longer open');
});

test('someone else cannot sync to a task that is not theirs', function () {
    $ctx  = stockTakeContext();
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [stAisle($ctx, 'A')->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperB'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => []])->assertForbidden();
});

test('counted offline at 10:00, sales at 11:00 and 13:00, uploaded at 14:00: the 10:00 figure is used', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();   // 50 on the books
    Carbon::setTestNow('2026-11-24 09:00:00');
    $task->update(['created_at' => now()]);

    // The phone counts 48 at 10:00 and keeps it.
    $countedAt = '2026-11-24T10:00:00.000Z';

    Carbon::setTestNow('2026-11-24 11:00:00');
    stSell($ctx, $milk, 3);
    Carbon::setTestNow('2026-11-24 13:00:00');
    stSell($ctx, $milk, 4);
    expect(stShelf($ctx, $milk))->toBe(43);

    Carbon::setTestNow('2026-11-24 14:00:00');
    $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [
        stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '48']], $countedAt),
    ]])->assertJsonPath('results.0.status', 'synced');

    $entry = StockTakeEntry::sole();
    expect($entry->counted_at->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and($entry->counted_at_flagged)->toBeFalse();

    app(StockTakeCounter::class)->submit($ctx['keeperA'], $task->fresh(), []);
    $result = StockTakeResult::sole();

    expect($result->system_qty_at_count)->toBe(50)
        ->and($result->variance_qty)->toBe(-2);

    Carbon::setTestNow('2026-11-24 15:00:00');
    app(StockTakeApprover::class)->approve($ctx['admin'], $result);

    // 43 on the books after the sales, two short: 41.
    expect(stShelf($ctx, $milk))->toBe(41);
});

test('a phone clock outside the task life is not believed: upload time, and the entry is flagged', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $aisle = stAisle($ctx, 'Aisle 2');
    Carbon::setTestNow('2026-11-24 09:00:00');
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    Carbon::setTestNow('2026-11-24 14:00:00');
    $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [
        // A phone set to last year.
        stSetOp($aisle->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '5']], '2025-01-01T10:00:00.000Z'),
    ]])->assertJsonPath('results.0.status', 'synced');

    $entry = StockTakeEntry::sole();

    expect($entry->counted_at->toDateTimeString())->toBe('2026-11-24 14:00:00')
        ->and($entry->counted_at_flagged)->toBeTrue()
        ->and($entry->device_counted_at->toDateTimeString())->toBe('2025-01-01 10:00:00');
});

test('a quick count\'s pack says so: its own area ("no aisle") plus the branch\'s aisles, nothing expected', function () {
    $ctx   = stockTakeContext();
    $one   = stAisle($ctx, 'Aisle 1');
    $task  = stPlanner()->assignQuickCount($ctx['admin'], stOpen($ctx), $ctx['keeperA'], null);
    $quick = $task->aisles()->first();

    $pack = $this->actingAs($ctx['keeperA'])->getJson(stOffline($ctx, $task, '/pack'))->assertOk()->json('task');

    expect($pack['quick'])->toBeTrue()
        ->and($pack['aisles'])->toHaveCount(2)
        ->and(collect($pack['aisles'])->firstWhere('id', $quick->id))->toMatchArray(['quick' => true, 'expected' => []])
        ->and(collect($pack['aisles'])->firstWhere('id', $one->id))->toMatchArray(['quick' => false, 'expected' => []]);
});

test('a quick count uploaded from the phone is not "off the aisle list", and hands in with nothing to answer', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $task  = stPlanner()->assignQuickCount($ctx['admin'], stOpen($ctx), $ctx['keeperA'], null);
    $quick = $task->aisles()->first();

    $result = $this->actingAs($ctx['keeperA'])->postJson(stOffline($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [
        stSetOp($quick->id, $milk->id, [['unit_id' => stPieceOf($milk), 'qty' => '9']]),
    ]])->json('results.0');

    $entry = StockTakeEntry::where('stock_take_task_id', $task->id)->firstOrFail();

    expect($result['status'])->toBe('synced')
        ->and((float) $entry->counted_qty_base)->toBe(9.0)
        ->and((bool) $entry->is_unlisted)->toBeFalse();

    $this->postJson(stOffline($ctx, $task, '/submit'), ['device' => 'test-phone'])->assertOk();
});

test('a recount offers the branch\'s real aisles, never a quick count\'s private area', function () {
    $ctx  = stockTakeContext();
    $real = stAisle($ctx, 'Aisle 1');
    $st   = stOpen($ctx);
    $quick = stPlanner()->assignQuickCount($ctx['admin'], $st, $ctx['keeperA'], null)->aisles()->first();

    $listed = \App\Models\Aisle::query()->where('store_id', $ctx['store']->id)->active()->listed()->pluck('id')->all();

    expect($listed)->toContain($real->id)->not->toContain($quick->id);
});
