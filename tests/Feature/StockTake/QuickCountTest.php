<?php

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Filament\Vendor\Resources\Aisles\Pages\ListAisles;
use App\Filament\Vendor\Resources\StockTakes\Pages\ViewStockTake;
use App\Models\Aisle;
use App\Models\ProductUnit;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeScope;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Quick count: a count task with no aisles and no mapping. The task gets a
 * private area of its own; the counter scans whatever they find.
 */

function stQuick(array $ctx, ?User $to = null, ?StockTake $st = null, ?string $due = null): StockTakeTask
{
    return stPlanner()->assignQuickCount($ctx['admin'], $st ?? stOpen($ctx), $to ?? $ctx['keeperA'], $due);
}

function stQuickUrl(array $ctx, StockTakeTask $task, string $path = ''): string
{
    return "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}{$path}";
}

function stQuickPanelAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['store']);
}

test('a quick count needs no aisles: the task gets a private area of its own', function () {
    $ctx  = stockTakeContext();
    $task = stQuick($ctx, $ctx['keeperA'], null, '2026-11-26');

    $area = $task->aisles()->get();

    expect($task->type)->toBe(StockTakeTask::TYPE_COUNT)
        ->and($task->assigned_to)->toBe($ctx['keeperA']->id)
        ->and($area)->toHaveCount(1)
        ->and($area[0]->is_quick)->toBeTrue()
        ->and($area[0]->name)->toStartWith(Str::before($ctx['keeperA']->name, ' ') . ' · 26 Nov')
        ->and($area[0]->products()->count())->toBe(0)
        ->and($task->stockTake->fresh()->status)->toBe(StockTake::STATUS_ACTIVE);
});

test('several people count at once, each in an area of their own; the same person twice is told apart', function () {
    $ctx = stockTakeContext();
    $st  = stOpen($ctx);

    $a = stQuick($ctx, $ctx['keeperA'], $st, '2026-11-26');
    $b = stQuick($ctx, $ctx['keeperB'], $st, '2026-11-26');
    $c = stQuick($ctx, $ctx['keeperA'], $st, '2026-11-26');

    $names = collect([$a, $b, $c])->map(fn ($t) => $t->aisles()->first()->name);

    expect($names->unique())->toHaveCount(3)
        ->and($names[2])->toEndWith('(2)');
});

test('quick areas stay out of the Aisles screen and the aisle pickers', function () {
    $ctx  = stockTakeContext();
    $real = stAisle($ctx, 'Aisle 1');
    $task = stQuick($ctx);
    $quick = $task->aisles()->first();

    stQuickPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)
        ->assertCanSeeTableRecords([$real])
        ->assertCanNotSeeTableRecords([$quick]);

    $options = collect(ViewStockTake::assignCountFields($task->stockTake))
        ->first(fn ($field) => $field->getName() === 'aisle_ids')
        ->getOptions();

    expect($options)->toHaveKey($real->id)->not->toHaveKey($quick->id);
    expect(AisleResource::getEloquentQuery()->pluck('aisles.id')->all())->toContain($real->id)->not->toContain($quick->id);
});

test('the counter scans anything: nothing is "not on the aisle list", and unmapped products count', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    $piece = ProductUnit::where('product_id', $milk->id)->where('is_base', true)->value('id');
    $task  = stQuick($ctx);
    $area  = $task->aisles()->first();

    $this->actingAs($ctx['keeperA'])
        ->postJson(stQuickUrl($ctx, $task, '/entries'), ['aisle_id' => $area->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece, 'qty' => '7']]])
        ->assertCreated()
        ->assertJsonPath('entry.counted', 7)
        ->assertJsonPath('entry.is_unlisted', false);

    $this->getJson(stQuickUrl($ctx, $task))
        ->assertOk()
        ->assertJsonPath('aisles.0.quick', true)
        ->assertJsonPath('aisles.0.counted', 1);
});

test('hand-in has nothing to answer: no product was expected, so none is missing', function () {
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Milk');
    stProduct($ctx, 'Never counted');
    $piece = ProductUnit::where('product_id', $milk->id)->where('is_base', true)->value('id');
    $task  = stQuick($ctx);
    $area  = $task->aisles()->first();

    $this->actingAs($ctx['keeperA'])
        ->postJson(stQuickUrl($ctx, $task, '/entries'), ['aisle_id' => $area->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece, 'qty' => '7']]])
        ->assertCreated();

    $this->getJson(stQuickUrl($ctx, $task, '/missing'))->assertOk()->assertJsonCount(0, 'missing');
    $this->postJson(stQuickUrl($ctx, $task, '/submit'), ['device' => 'test-phone'])->assertOk();

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_SUBMITTED);
});

test('what nobody counted shows as uncounted once a quick count exists; before that only mapped products', function () {
    $ctx     = stockTakeContext();
    $milk    = stProduct($ctx, 'Milk');
    $rice    = stProduct($ctx, 'Rice');
    $empty   = stProduct($ctx, 'Out of stock', 0);
    $st      = stOpen($ctx);
    $aisle   = stAisle($ctx, 'Aisle 1');
    stMap($ctx, $aisle, $milk);

    // Mapped products only, as before.
    expect(StockTakeScope::hasQuickCount($st))->toBeFalse()
        ->and(StockTakeScope::inScope($st)->all())->toBe([$milk->id]);

    $task = stQuick($ctx, $ctx['keeperA'], $st);

    // Now the whole branch is in scope (not the product with no stock).
    expect(StockTakeScope::hasQuickCount($st))->toBeTrue()
        ->and(StockTakeScope::inScope($st)->sort()->values()->all())->toBe(collect([$milk->id, $rice->id])->sort()->values()->all())
        ->and(StockTakeScope::inScope($st)->all())->not->toContain($empty->id);

    // Counted and handed in: only the rest is uncounted.
    StockTakeEntry::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'stock_take_task_id' => $task->id,
        'aisle_id' => $task->aisles()->first()->id, 'product_id' => $rice->id, 'counted_by' => $ctx['keeperA']->id,
        'counted_qty_base' => 4, 'counted_at' => now(),
    ]);
    $task->update(['status' => StockTakeTask::STATUS_SUBMITTED, 'submitted_at' => now()]);

    expect(StockTakeScope::uncounted($st)->all())->toBe([$milk->id]);
});

test('a product handed in by one quick count is done for another, as ever', function () {
    $ctx  = stockTakeContext();
    $milk = stProduct($ctx, 'Milk');
    $st   = stOpen($ctx);
    $one  = stQuick($ctx, $ctx['keeperA'], $st);

    StockTakeEntry::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'stock_take_task_id' => $one->id,
        'aisle_id' => $one->aisles()->first()->id, 'product_id' => $milk->id, 'counted_by' => $ctx['keeperA']->id,
        'counted_qty_base' => 5, 'counted_at' => now(),
    ]);
    $one->update(['status' => StockTakeTask::STATUS_SUBMITTED, 'submitted_at' => now()]);

    $two   = stQuick($ctx, $ctx['keeperB'], $st);
    $piece = ProductUnit::where('product_id', $milk->id)->where('is_base', true)->value('id');

    $this->actingAs($ctx['keeperB'])
        ->postJson(stQuickUrl($ctx, $two, '/entries'), ['aisle_id' => $two->aisles()->first()->id, 'product_id' => $milk->id, 'lines' => [['unit_id' => $piece, 'qty' => '3']]])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Already counted'));
});

test('a manager gives a quick count from the stock take screen; without it, aisles are still required', function () {
    $ctx = stockTakeContext();
    $st  = stOpen($ctx);
    stQuickPanelAs($ctx, $ctx['admin']);

    Livewire::test(ViewStockTake::class, ['record' => $st->getKey()])
        ->callAction('assign_count', ['quick' => false, 'aisle_ids' => [], 'assigned_to' => $ctx['keeperA']->id])
        ->assertHasActionErrors(['aisle_ids' => 'required']);

    expect(StockTakeTask::where('stock_take_id', $st->id)->count())->toBe(0);

    Livewire::test(ViewStockTake::class, ['record' => $st->getKey()])
        ->callAction('assign_count', ['quick' => true, 'assigned_to' => $ctx['keeperA']->id])
        ->assertHasNoActionErrors();

    $task = StockTakeTask::where('stock_take_id', $st->id)->firstOrFail();
    expect($task->assigned_to)->toBe($ctx['keeperA']->id)
        ->and($task->aisles()->first()->is_quick)->toBeTrue();
});

test('only someone who may manage stock takes can give a quick count', function () {
    $ctx = stockTakeContext();

    expect(fn () => stPlanner()->assignQuickCount($ctx['keeperA'], stOpen($ctx), $ctx['keeperB'], null))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});
