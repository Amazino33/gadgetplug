<?php

use App\Models\ProductUnit;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Services\StockTake\StockTakeScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Step 5: counting on the phone. Each save reaches the server at once;
 * nothing a counter sees carries a system figure.
 */

function stCountUrl(array $ctx, StockTakeTask $task, string $path = ''): string
{
    return "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}{$path}";
}

/** A product sold by the piece and by the crate of 12. */
function stCrated(array $ctx, string $name = 'Coke 50cl'): array
{
    $product = stProduct($ctx, $name, 50);
    $piece   = ProductUnit::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
    $crate   = ProductUnit::create([
        'product_id' => $product->id, 'vendor_id' => $ctx['vendor']->id,
        'name' => 'Crate', 'conversion_factor' => 12, 'price' => 6000, 'is_base' => false, 'is_active' => true,
    ]);

    return [$product, $piece, $crate];
}

/** All keys anywhere in a JSON body. */
function stAllKeys($data): array
{
    $keys = [];
    $walk = function ($value) use (&$walk, &$keys) {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $inner) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $walk($inner);
        }
    };
    $walk($data);

    return array_values(array_unique($keys));
}

test('3 crates and 5 pieces is saved as 41 pieces, at the server\'s time', function () {
    Carbon::setTestNow('2026-11-24 10:15:00');
    $ctx = stockTakeContext();
    [$coke, $piece, $crate] = stCrated($ctx);
    $aisle = stAisle($ctx, 'Drinks');
    stMap($ctx, $aisle, $coke);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA'])
        ->postJson(stCountUrl($ctx, $task, '/entries'), [
            'aisle_id' => $aisle->id, 'product_id' => $coke->id,
            'lines' => [['unit_id' => $crate->id, 'qty' => '3'], ['unit_id' => $piece->id, 'qty' => '5']],
        ])
        ->assertCreated()
        ->assertJsonPath('entry.counted', 41);

    $entry = StockTakeEntry::sole();

    expect($entry->counted_qty_base)->toBe(41)
        ->and($entry->counted_at->toDateTimeString())->toBe('2026-11-24 10:15:00')
        ->and(collect($entry->entry_breakdown)->pluck('qty', 'unit')->all())->toBe(['Crate' => 3, 'Piece' => 5])
        ->and($task->fresh()->status)->toBe(StockTakeTask::STATUS_IN_PROGRESS);

    Carbon::setTestNow();
});

test('a second count of the same product in the same aisle asks: Add or Replace', function () {
    $ctx   = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $aisle = stAisle($ctx, 'Drinks');
    stMap($ctx, $aisle, $coke);
    $coke->update(['barcode' => '544900']);
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $save  = fn ($qty, $mode = null) => $this->postJson(stCountUrl($ctx, $task, '/entries'), array_filter([
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'mode' => $mode,
        'lines' => [['unit_id' => $piece->id, 'qty' => $qty]],
    ]));

    $this->actingAs($ctx['keeperA']);
    $save(12)->assertCreated();

    // Scanning it again tells the phone what is already there.
    $this->postJson(stCountUrl($ctx, $task, '/scan'), ['aisle_id' => $aisle->id, 'code' => '544900'])
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('existing.counted', 12);

    // Never decided silently.
    $save(5)->assertStatus(422)->assertJsonValidationErrors('mode');

    $save(5, 'add')->assertJsonPath('entry.counted', 17);
    expect(StockTakeEntry::sole()->entry_breakdown[0]['qty'])->toBe(17);

    $save(5, 'replace')->assertJsonPath('entry.counted', 5);
    expect(StockTakeEntry::count())->toBe(1);
});

test('20 on Aisle 2 and 30 in the store room are two entries that add up to 50', function () {
    $ctx  = stockTakeContext();
    [$milk, $piece] = stCrated($ctx, 'Peak Milk');
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    stMap($ctx, $a2, $milk);
    stMap($ctx, $room, $milk);
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$a2->id, $room->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA']);
    foreach ([[$a2, 20], [$room, 30]] as [$aisle, $qty]) {
        $this->postJson(stCountUrl($ctx, $task, '/entries'), [
            'aisle_id' => $aisle->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece->id, 'qty' => $qty]],
        ])->assertCreated();
    }

    expect(StockTakeEntry::where('product_id', $milk->id)->sum('counted_qty_base'))->toEqual(50);
});

test('blind: nothing the counter receives carries stock, cost or price', function () {
    $ctx = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $coke->update(['barcode' => '544900']);
    $aisle = stAisle($ctx, 'Drinks');
    stMap($ctx, $aisle, $coke, stProduct($ctx, 'Uncounted Fanta'));
    $task = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA']);

    $bodies = [
        $this->getJson("/stock-take/{$ctx['vendor']->slug}/api/home")->json(),
        $this->getJson(stCountUrl($ctx, $task))->json(),
        $this->postJson(stCountUrl($ctx, $task, '/scan'), ['aisle_id' => $aisle->id, 'code' => '544900'])->json(),
        $this->postJson(stCountUrl($ctx, $task, '/pick'), ['aisle_id' => $aisle->id, 'product_id' => $coke->id])->json(),
        $this->postJson(stCountUrl($ctx, $task, '/entries'), ['aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 48]]])->json(),
        $this->getJson(stCountUrl($ctx, $task, "/entries?aisle_id={$aisle->id}"))->json(),
        $this->getJson(stCountUrl($ctx, $task, '/missing'))->json(),
    ];

    foreach ($bodies as $body) {
        expect(stAllKeys($body))
            ->not->toContain('stock')->not->toContain('stock_quantity')->not->toContain('quantity')
            ->not->toContain('system_qty_at_count')->not->toContain('system_quantity')
            ->not->toContain('cost_price')->not->toContain('price')->not->toContain('unit_cost');
    }

    // And the figure itself, 50, is no value anywhere in what was sent back —
    // ids aside, which on MySQL can reach 50 by chance in a long run.
    $values = [];
    $walk = function ($data, $key = null) use (&$walk, &$values) {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $walk($v, $k);
            }

            return;
        }
        if (! (is_string($key) && ($key === 'id' || str_ends_with($key, '_id')))) {
            $values[] = $data;
        }
    };
    $walk($bodies);

    expect(array_filter($values, fn ($v) => is_numeric($v) && (float) $v === 50.0))->toBe([]);
});

test('a product not on this aisle\'s list is accepted and marked as found here', function () {
    $ctx = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $coke->update(['barcode' => '544900']);
    $aisle = stAisle($ctx, 'Snacks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA'])
        ->postJson(stCountUrl($ctx, $task, '/scan'), ['aisle_id' => $aisle->id, 'code' => '544900'])
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('unlisted', true);

    $this->postJson(stCountUrl($ctx, $task, '/entries'), [
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 2]],
    ])->assertJsonPath('entry.is_unlisted', true);
});

test('an unknown barcode is recorded with name, photo, quantity and unit', function () {
    Storage::fake('public');
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Snacks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA'])
        ->postJson(stCountUrl($ctx, $task, '/scan'), ['aisle_id' => $aisle->id, 'code' => '777000'])
        ->assertJsonPath('status', 'unknown');

    $this->post(stCountUrl($ctx, $task, '/unknown-items'), [
        'aisle_id' => $aisle->id, 'barcode' => '777000', 'name' => 'Plantain Chips',
        'qty' => 6, 'unit' => 'Pack', 'photo' => UploadedFile::fake()->image('chips.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $item = StockTakeUnknownItem::sole();

    expect($item->stock_take_task_id)->toBe($task->id)
        ->and($item->qty)->toBe(6)
        ->and($item->unit_entered)->toBe('Pack');
    Storage::disk('public')->assertExists($item->photo_path);
});

test('decision 5: counted Tuesday in Aisle 2, scanned Thursday in the Store Room, is refused', function () {
    $ctx = stockTakeContext();
    [$milk, $piece] = stCrated($ctx, 'Peak Milk');
    $milk->update(['barcode' => '600100']);
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    stMap($ctx, $a2, $milk);
    stMap($ctx, $room, $milk);
    $st = stOpen($ctx);

    // Tuesday 24 Nov: Aisle 2 counted and handed in.
    Carbon::setTestNow('2026-11-24 10:00:00');
    $tuesday = stPlanner()->assignCount($ctx['admin'], $st, [$a2->id], $ctx['keeperA'], null);
    $this->actingAs($ctx['keeperA']);
    $this->postJson(stCountUrl($ctx, $tuesday, '/entries'), [
        'aisle_id' => $a2->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 20]],
    ])->assertCreated();
    $this->postJson(stCountUrl($ctx, $tuesday, '/submit'), ['device' => 'test-phone'])->assertOk();

    // Thursday 26 Nov: the store room, by someone else.
    Carbon::setTestNow('2026-11-26 09:00:00');
    $thursday = stPlanner()->assignCount($ctx['admin'], $st, [$room->id], $ctx['keeperB'], null);
    $this->actingAs($ctx['keeperB']);

    $this->postJson(stCountUrl($ctx, $thursday, '/scan'), ['aisle_id' => $room->id, 'code' => '600100'])
        ->assertJsonPath('status', 'already_done')
        ->assertJsonPath('counted_on', 'Tuesday 24 Nov')
        ->assertJsonPath('counted_in', ['Aisle 2']);

    // Saving anyway is refused too, and nothing is stored.
    $this->postJson(stCountUrl($ctx, $thursday, '/entries'), [
        'aisle_id' => $room->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 30]],
    ])->assertStatus(422)->assertJsonValidationErrors('product_id');

    expect(StockTakeEntry::where('stock_take_task_id', $thursday->id)->count())->toBe(0)
        // Nor is it among what Thursday's task is expected to find.
        ->and(StockTakeScope::expectedFor($thursday)->all())->toBe([]);

    Carbon::setTestNow();
});

test('halves only for a shop that sells halves', function () {
    $ctx = stockTakeContext();
    [$coke, , $crate] = stCrated($ctx);
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $half  = fn () => $this->postJson(stCountUrl($ctx, $task, '/entries'), [
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'mode' => 'replace',
        'lines' => [['unit_id' => $crate->id, 'qty' => '1.5']],
    ]);

    $this->actingAs($ctx['keeperA']);
    $half()->assertStatus(422)->assertJsonValidationErrors('lines');

    $ctx['vendor']->forceFill(['allow_half_quantities' => true])->saveQuietly();

    $half()->assertCreated()->assertJsonPath('entry.counted', 18);
});

test('an empty count is refused; a typed 0 is a count of nothing', function () {
    $ctx = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA']);
    $this->postJson(stCountUrl($ctx, $task, '/entries'), ['aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => '']]])
        ->assertStatus(422);
    $this->postJson(stCountUrl($ctx, $task, '/entries'), ['aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => '0']]])
        ->assertCreated()->assertJsonPath('entry.counted', 0);
});

test('submit asks about every mapped product with no count; skipped ones stay uncounted', function () {
    $ctx = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $fanta  = stProduct($ctx, 'Fanta');
    $sprite = stProduct($ctx, 'Sprite');
    $aisle  = stAisle($ctx, 'Drinks');
    stMap($ctx, $aisle, $coke, $fanta, $sprite);
    $st   = stOpen($ctx);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA']);
    $this->postJson(stCountUrl($ctx, $task, '/entries'), [
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 9]],
    ])->assertCreated();

    $this->getJson(stCountUrl($ctx, $task, '/missing'))->assertJsonCount(2, 'missing');

    // Not every missing product answered.
    $this->postJson(stCountUrl($ctx, $task, '/submit'), ['device' => 'test-phone', 'decisions' => [$fanta->id => 'not_on_shelf']])
        ->assertStatus(422)->assertJsonValidationErrors('decisions');

    $this->postJson(stCountUrl($ctx, $task, '/submit'), ['device' => 'test-phone', 'decisions' => [$fanta->id => 'not_on_shelf', $sprite->id => 'skip']])
        ->assertOk();

    $zero = StockTakeEntry::where('product_id', $fanta->id)->sole();

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_SUBMITTED)
        ->and($zero->counted_qty_base)->toBe(0)
        ->and($zero->is_not_on_shelf)->toBeTrue()
        ->and(StockTakeEntry::where('product_id', $sprite->id)->exists())->toBeFalse()
        ->and(StockTakeScope::uncounted($st)->all())->toBe([$sprite->id]);

    // Handed in: no more changes.
    $this->postJson(stCountUrl($ctx, $task, '/entries'), [
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'mode' => 'replace', 'lines' => [['unit_id' => $piece->id, 'qty' => 1]],
    ])->assertForbidden();
    $this->deleteJson(stCountUrl($ctx, $task, "/entries/{$zero->id}"))->assertForbidden();
});

test('a count can be taken back before submit', function () {
    $ctx = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);

    $this->actingAs($ctx['keeperA']);
    $id = $this->postJson(stCountUrl($ctx, $task, '/entries'), [
        'aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 3]],
    ])->json('entry.id');

    $this->deleteJson(stCountUrl($ctx, $task, "/entries/{$id}"))->assertOk();

    expect(StockTakeEntry::count())->toBe(0);
});

test('only the assignee counts, only in the task\'s aisles, and never across shops', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    [$coke, $piece] = stCrated($ctx);
    $mine  = stAisle($ctx, 'Drinks');
    $notOn = stAisle($ctx, 'Bakery');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$mine->id], $ctx['keeperA'], null);
    $body  = fn ($aisle) => ['aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 1]]];

    $this->actingAs($ctx['keeperB'])->postJson(stCountUrl($ctx, $task, '/entries'), $body($mine))->assertForbidden();
    $this->actingAs($ctx['keeperA'])->postJson(stCountUrl($ctx, $task, '/entries'), $body($notOn))->assertStatus(422);
    $this->actingAs($other['keeperA'])->getJson(stCountUrl($ctx, $task))->assertForbidden();

    // Another shop's aisle id, through this shop's task.
    $this->actingAs($ctx['keeperA'])->postJson(stCountUrl($ctx, $task, '/entries'), $body(stAisle($other, 'Theirs')))->assertNotFound();

    expect(StockTakeEntry::count())->toBe(0);
});

test('a recount counts only its products, in whatever aisle they are found, even if done', function () {
    $ctx = stockTakeContext();
    [$milk, $piece] = stCrated($ctx, 'Peak Milk');
    $sugar = stProduct($ctx, 'Sugar');
    $sugar->update(['barcode' => '800800']);
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    stMap($ctx, $a2, $milk, $sugar);
    $st       = stOpen($ctx);
    $original = stHandIn($ctx, $st, $a2, [$milk, $sugar]);

    $recount = StockTakeTask::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'stock_take_id' => $st->id,
        'type' => StockTakeTask::TYPE_RECOUNT, 'parent_task_id' => $original->id,
        'assigned_to' => $ctx['keeperB']->id, 'created_by' => $ctx['admin']->id,
    ]);
    $recount->products()->attach($milk->id);

    $this->actingAs($ctx['keeperB']);

    $this->getJson(stCountUrl($ctx, $recount))
        ->assertJsonPath('task.type', 'recount')
        ->assertJsonPath('products.0.id', $milk->id)
        ->assertJsonCount(2, 'branch_aisles');

    // Found in the store room, which it is not even mapped to.
    $this->postJson(stCountUrl($ctx, $recount, '/entries'), [
        'aisle_id' => $room->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece->id, 'qty' => 48]],
    ])->assertCreated();

    $this->postJson(stCountUrl($ctx, $recount, '/scan'), ['aisle_id' => $room->id, 'code' => '800800'])
        ->assertJsonPath('status', 'not_in_recount');

    $this->postJson(stCountUrl($ctx, $recount, '/submit'), ['device' => 'test-phone'])->assertOk();

    expect($recount->fresh()->status)->toBe(StockTakeTask::STATUS_SUBMITTED);
});

test('a counter with an open count task sees it on My Tasks', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], '2026-11-25');

    $this->actingAs($ctx['keeperA'])
        ->getJson("/stock-take/{$ctx['vendor']->slug}/api/home")
        ->assertJsonPath('tasks.0.id', $task->id)
        ->assertJsonPath('tasks.0.type', 'count');
});
