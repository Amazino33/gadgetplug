<?php

use App\Models\Aisle;
use App\Models\AisleProduct;
use App\Models\ProductUnit;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Count and map in one walk: a Quick count says which real aisle the counter
 * is in as they go. Each count is saved with that aisle and puts the product
 * on its map; a counter can add an aisle nobody set up.
 */

function cmQuick(array $ctx, ?User $to = null, ?StockTake $st = null): StockTakeTask
{
    return stPlanner()->assignQuickCount($ctx['admin'], $st ?? stOpen($ctx), $to ?? $ctx['keeperA'], null);
}

function cmUrl(array $ctx, StockTakeTask $task, string $path = ''): string
{
    $query = $path === '/pack' ? '?device=test-phone' : '';

    return "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}{$path}{$query}";
}

function cmSet(int $aisleId, int $productId, int $unitId, string $qty): array
{
    return [
        'id' => (string) Str::uuid(), 'type' => 'set', 'aisle_id' => $aisleId, 'product_id' => $productId,
        'lines' => [['unit_id' => $unitId, 'qty' => $qty]],
        'counted_at' => now()->toIso8601ZuluString(), 'device_counted_at' => now()->toIso8601ZuluString(),
    ];
}

function cmPiece($product): int
{
    return (int) ProductUnit::where('product_id', $product->id)->where('is_base', true)->value('id');
}

test('the pack of a Quick count carries every aisle of the branch, with nothing expected', function () {
    $ctx  = stockTakeContext();
    $one  = stAisle($ctx, 'Aisle 1');
    $two  = stAisle($ctx, 'Aisle 2');
    $milk = stProduct($ctx, 'Milk');
    $one->products()->attach($milk->id, ['vendor_id' => $ctx['vendor']->id, 'source' => 'mapping', 'mapped_at' => now()]);
    $task = cmQuick($ctx);

    $pack = $this->actingAs($ctx['keeperA'])->getJson(cmUrl($ctx, $task, '/pack'))->assertOk()->json();
    $aisles = collect($pack['task']['aisles']);

    expect($pack['task']['quick'])->toBeTrue()
        ->and($aisles->pluck('id')->all())->toContain($one->id, $two->id)
        ->and($aisles->firstWhere('quick', true))->not->toBeNull()
        ->and($aisles->pluck('expected')->flatten()->all())->toBe([]);
});

test('counting in a real aisle saves the count there and puts the product on that aisle\'s map', function () {
    $ctx  = stockTakeContext();
    $two  = stAisle($ctx, 'Aisle 2');
    $milk = stProduct($ctx, 'Milk');
    $task = cmQuick($ctx);

    $sync = $this->actingAs($ctx['keeperA'])
        ->postJson(cmUrl($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [cmSet($two->id, $milk->id, cmPiece($milk), '7')]])
        ->assertOk()->json();

    expect($sync['results'][0]['status'] ?? $sync[0]['status'] ?? null)->toBe('synced');

    $entry = StockTakeEntry::sole();
    expect($entry->aisle_id)->toBe($two->id)
        ->and((float) $entry->counted_qty_base)->toBe(7.0)
        ->and($entry->is_unlisted)->toBeFalse();

    $map = AisleProduct::where('aisle_id', $two->id)->where('product_id', $milk->id)->sole();
    expect($map->source)->toBe(AisleProduct::SOURCE_COUNT)
        ->and($map->mapped_by)->toBe($ctx['keeperA']->id);
});

test('"no aisle" still works: counted in the task\'s own area, mapped nowhere', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $task = cmQuick($ctx);
    $area = $task->aisles()->first();

    $this->actingAs($ctx['keeperA'])
        ->postJson(cmUrl($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [cmSet($area->id, $milk->id, cmPiece($milk), '2')]])
        ->assertOk();

    expect(StockTakeEntry::sole()->aisle_id)->toBe($area->id)
        ->and(AisleProduct::count())->toBe(0);
});

test('an aisle another person\'s aisle task holds is refused, with who has it', function () {
    $ctx   = stockTakeContext();
    $st    = stOpen($ctx);
    $held  = stAisle($ctx, 'Aisle 5');
    $milk  = stProduct($ctx, 'Milk');
    stPlanner()->assignCount($ctx['admin'], $st, [$held->id], $ctx['keeperB'], null);
    $task  = cmQuick($ctx, $ctx['keeperA'], $st);

    $pack = $this->actingAs($ctx['keeperA'])->getJson(cmUrl($ctx, $task, '/pack'))->json();
    expect(collect($pack['task']['aisles'])->firstWhere('id', $held->id)['held_by'])->toBe($ctx['keeperB']->name);

    $sync = $this->postJson(cmUrl($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [cmSet($held->id, $milk->id, cmPiece($milk), '1')]])->json();
    $row  = $sync['results'][0] ?? $sync[0];

    expect($row['status'])->toBe('rejected')
        ->and($row['reason'])->toContain($ctx['keeperB']->name);
    expect(StockTakeEntry::count())->toBe(0);
});

test('an aisle task still counts only in its own aisles', function () {
    $ctx   = stockTakeContext();
    $st    = stOpen($ctx);
    $mine  = stAisle($ctx, 'Aisle 1');
    $other = stAisle($ctx, 'Aisle 2');
    $milk  = stProduct($ctx, 'Milk');
    $task  = stPlanner()->assignCount($ctx['admin'], $st, [$mine->id], $ctx['keeperA'], null);

    $sync = $this->actingAs($ctx['keeperA'])
        ->postJson(cmUrl($ctx, $task, '/sync'), ['device' => 'test-phone', 'ops' => [cmSet($other->id, $milk->id, cmPiece($milk), '1')]])
        ->json();

    expect(($sync['results'][0] ?? $sync[0])['status'])->toBe('rejected')
        ->and(AisleProduct::count())->toBe(0);
});

test('a counter adds an aisle nobody set up; the same name again is that aisle, not a second one', function () {
    $ctx  = stockTakeContext();
    $task = cmQuick($ctx);

    $first = $this->actingAs($ctx['keeperA'])
        ->postJson(cmUrl($ctx, $task, '/aisles'), ['name' => '  Back   store '])
        ->assertCreated()
        ->json('aisle');

    expect($first['label'])->toBe('Back store')
        ->and($first['expected'])->toBe([]);

    $again = $this->postJson(cmUrl($ctx, $task, '/aisles'), ['name' => 'back store'])->assertOk()->json('aisle');
    expect($again['id'])->toBe($first['id'])
        ->and(Aisle::where('store_id', $ctx['store']->id)->where('is_quick', false)->count())->toBe(1);

    $aisle = Aisle::find($first['id']);
    expect($aisle->is_active)->toBeTrue()->and($aisle->is_quick)->toBeFalse();
});

test('only a Quick count, by its own counter, can add an aisle', function () {
    $ctx   = stockTakeContext();
    $st    = stOpen($ctx);
    $aisle = stAisle($ctx, 'Aisle 1');
    $count = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);
    $quick = cmQuick($ctx, $ctx['keeperA'], $st);

    $this->actingAs($ctx['keeperA'])->postJson(cmUrl($ctx, $count, '/aisles'), ['name' => 'Fridge'])->assertStatus(422);
    $this->actingAs($ctx['keeperB'])->postJson(cmUrl($ctx, $quick, '/aisles'), ['name' => 'Fridge'])->assertForbidden();

    expect(Aisle::where('name', 'Fridge')->exists())->toBeFalse();
});

test('the pack carries a blind ceiling per product for the "is that right?" check, never the stock itself', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk', 10);
    $task = cmQuick($ctx);

    $pack = $this->actingAs($ctx['keeperA'])->getJson(cmUrl($ctx, $task, '/pack'))->json();
    $row  = collect($pack['products'])->firstWhere(0, $milk->id);

    expect($row[7])->toBe(50)          // about 3 × 10, rounded up to a round number
        ->and($row)->not->toContain(10.0)
        ->and(\App\Services\StockTake\StockTakePack::ceiling(1700, []))->toBe(10000)
        ->and(\App\Services\StockTake\StockTakePack::ceiling(0, [[1, 'Crate', 24], [2, 'Piece', 1]]))->toBe(50);
});
