<?php

use App\Models\AisleProduct;
use App\Models\StockTakeTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Fast mapping: the phone keeps the shop's products and finds a scan itself,
 * then saves what it put on the aisle in batches.
 */

function fmUrl(array $ctx, string $path): string
{
    return "/stock-take/{$ctx['vendor']->slug}/api{$path}";
}

function fmMappingTask(array $ctx, User $to, array $aisles): StockTakeTask
{
    $task = StockTakeTask::create([
        'vendor_id'   => $ctx['vendor']->id,
        'store_id'    => $ctx['store']->id,
        'type'        => StockTakeTask::TYPE_MAPPING,
        'assigned_to' => $to->id,
        'due_date'    => today(),
        'created_by'  => $ctx['admin']->id,
    ]);
    $task->attachAisles(collect($aisles)->pluck('id')->all());

    return $task;
}

test('the mapping download carries every product with its barcodes, and no stock, cost or price', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Peak Milk 400g');
    $milk->update(['barcode' => '6001']);
    stProduct($ctx, 'Rice 5kg');

    $data = $this->actingAs($ctx['admin'])->getJson(fmUrl($ctx, '/mapping-catalogue'))->assertOk()->json();

    expect($data['products'])->toHaveCount(2)
        ->and($data['v'])->toBeString();

    $row = collect($data['products'])->firstWhere(0, $milk->id);
    expect($row[1])->toBe($milk->name)
        ->and(collect($row[6])->pluck(0)->all())->toContain('6001');

    $json = json_encode($data);
    expect($json)->not->toContain('"price"')->not->toContain('cost')->not->toContain('quantity');
});

test('"anything new?" is answered without the list, and a new barcode changes the answer', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx);

    $v = $this->actingAs($ctx['admin'])->getJson(fmUrl($ctx, '/mapping-catalogue'))->json('v');

    $this->getJson(fmUrl($ctx, "/mapping-catalogue?v={$v}"))->assertExactJson(['same' => true, 'v' => $v]);

    $this->travel(2)->seconds();
    $milk->update(['barcode' => '6009']);

    $fresh = $this->getJson(fmUrl($ctx, "/mapping-catalogue?v={$v}"))->json();
    expect($fresh['v'])->not->toBe($v)
        ->and(json_encode($fresh['products']))->toContain('6009');
});

test('a batch from the phone puts each product on the aisle once', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 2');
    $a     = stProduct($ctx, 'A');
    $b     = stProduct($ctx, 'B');

    $this->actingAs($ctx['admin'])
        ->postJson(fmUrl($ctx, "/aisles/{$aisle->id}/scan"), ['code' => $a->sku])
        ->assertJsonPath('status', 'added');

    $this->postJson(fmUrl($ctx, "/aisles/{$aisle->id}/map"), ['product_ids' => [$a->id, $b->id, $b->id, 999999]])
        ->assertOk()
        ->assertExactJson(['added' => [$b->id], 'already' => [$a->id], 'missing' => [999999]]);

    expect(AisleProduct::where('aisle_id', $aisle->id)->pluck('product_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('a batch counts as work on the mapping task, and only for an aisle they may map', function () {
    $ctx   = stockTakeContext();
    $mine  = stAisle($ctx, 'Aisle 1');
    $other = stAisle($ctx, 'Aisle 9');
    $item  = stProduct($ctx);
    $task  = fmMappingTask($ctx, $ctx['keeperA'], [$mine]);

    $this->actingAs($ctx['keeperA'])
        ->postJson(fmUrl($ctx, "/aisles/{$mine->id}/map"), ['product_ids' => [$item->id], 'task_id' => $task->id])
        ->assertJsonPath('added', [$item->id]);

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_IN_PROGRESS);

    $this->postJson(fmUrl($ctx, "/aisles/{$other->id}/map"), ['product_ids' => [$item->id]])->assertForbidden();
    expect($other->products()->count())->toBe(0);
});

test('another shop\'s products are never mapped, and its people get nothing', function () {
    $ctx    = stockTakeContext();
    $aisle  = stAisle($ctx, 'Aisle 2');
    $other  = stockTakeContext();
    $theirs = stProduct($other, 'Theirs');

    $this->actingAs($ctx['admin'])
        ->postJson(fmUrl($ctx, "/aisles/{$aisle->id}/map"), ['product_ids' => [$theirs->id]])
        ->assertJsonPath('missing', [$theirs->id]);

    expect($aisle->products()->count())->toBe(0);

    $this->actingAs($other['admin'])->getJson(fmUrl($ctx, '/mapping-catalogue'))->assertForbidden();
});
