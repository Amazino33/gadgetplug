<?php

use App\Filament\Vendor\Resources\StockTakes\Pages\ListStockTakes;
use App\Filament\Vendor\Resources\StockTakes\Pages\ViewStockTake;
use App\Filament\Vendor\Resources\StockTakes\RelationManagers\TasksRelationManager;
use App\Filament\Vendor\Resources\StockTakes\StockTakeResource;
use App\Filament\Vendor\Resources\StockTakes\Widgets\NotMappedProducts;
use App\Filament\Vendor\Resources\StockTakes\Widgets\StockTakeProgress;
use App\Filament\Vendor\Resources\StockTakes\Widgets\UncountedProducts;
use App\Filament\Vendor\Resources\StockTakes\Widgets\UnknownItems;
use App\Models\StockTake;
use App\Models\StockTakeTask;
use App\Models\StockTakeUnknownItem;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveStore;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Step 4: the Stock Takes screens.
 */

function stDashboardAs(array $ctx, User $user, ?Store $store = null): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $store ?? $ctx['store']);
}

test('opening a stock take from the list lands on its dashboard', function () {
    $ctx = stockTakeContext();
    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ListStockTakes::class)
        ->callAction('open', [
            'name' => 'November Full Count', 'start_date' => '2026-11-24', 'end_date' => '2026-11-30',
            'variance_threshold_percent' => 10, 'variance_threshold_value' => 5000,
        ])
        ->assertRedirect(StockTakeResource::getUrl('view', ['record' => StockTake::sole()]));

    expect(StockTake::sole()->store_id)->toBe($ctx['store']->id);
});

test('a second open stock take is refused with a message, not an error page', function () {
    $ctx = stockTakeContext();
    stOpen($ctx);
    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ListStockTakes::class)
        ->callAction('open', ['name' => 'Again', 'start_date' => '2026-11-24', 'end_date' => '2026-11-30',
            'variance_threshold_percent' => 10, 'variance_threshold_value' => 5000])
        ->assertNotified('Not done');

    expect(StockTake::count())->toBe(1);
});

test('the dashboard shows progress, uncounted, not mapped and unknown items', function () {
    $ctx  = stockTakeContext();
    $a1   = stAisle($ctx, 'Aisle 1');
    $a2   = stAisle($ctx, 'Aisle 2');
    [$p1, $p2] = [stProduct($ctx, 'Counted Milk'), stProduct($ctx, 'Waiting Sugar')];
    $loose = stProduct($ctx, 'Loose Rice', 4);
    stMap($ctx, $a1, $p1);
    stMap($ctx, $a2, $p2);
    $st = stOpen($ctx);
    stHandIn($ctx, $st, $a1, [$p1]);
    StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'aisle_id' => $a2->id,
        'barcode' => '999', 'name_entered' => 'Mystery Tin', 'created_by' => $ctx['keeperA']->id,
    ]);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ViewStockTake::class, ['record' => $st->id])->assertOk()->assertSee($st->reference);

    Livewire::test(StockTakeProgress::class, ['record' => $st])
        ->assertSee('In scope')->assertSee('50%');

    Livewire::test(UncountedProducts::class, ['record' => $st])
        ->assertCanSeeTableRecords([$p2])
        ->assertCanNotSeeTableRecords([$p1]);

    Livewire::test(NotMappedProducts::class, ['record' => $st])
        ->assertCanSeeTableRecords([$loose])
        ->assertCanNotSeeTableRecords([$p1, $p2]);

    Livewire::test(UnknownItems::class, ['record' => $st])->assertSee('Mystery Tin');
});

test('a count task is assigned from the dashboard, and the stock take turns active', function () {
    $ctx = stockTakeContext();
    $a1  = stAisle($ctx, 'Aisle 1');
    $st  = stOpen($ctx);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ViewStockTake::class, ['record' => $st->id])
        ->callAction('assign_count', ['aisle_ids' => [(string) $a1->id], 'assigned_to' => $ctx['keeperA']->id, 'due_date' => '2026-11-25'])
        ->assertNotified('Count task assigned');

    $task = StockTakeTask::sole();

    expect($task->assigned_to)->toBe($ctx['keeperA']->id)
        ->and($task->aisles->pluck('id')->all())->toBe([$a1->id])
        ->and($st->fresh()->status)->toBe(StockTake::STATUS_ACTIVE);
});

test('the same-day warning appears for the chosen aisles and can add the other places', function () {
    $ctx  = stockTakeContext();
    $a2   = stAisle($ctx, 'Aisle 2');
    $room = stAisle($ctx, 'Store Room');
    $milk = stProduct($ctx, 'Peak Milk 400g');
    stMap($ctx, $a2, $milk);
    stMap($ctx, $room, $milk);
    $st = stOpen($ctx);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ViewStockTake::class, ['record' => $st->id])
        ->mountAction('assign_count')
        ->fillForm(['aisle_ids' => [(string) $a2->id]])
        ->assertSchemaComponentVisible('same_day_warning')
        ->assertMountedActionModalSee(['Peak Milk 400g', 'is also in', 'Store Room', 'counted the same day'])
        ->callAction(TestAction::make('add_warned_aisles')->schemaComponent('same_day_warning'))
        ->assertSchemaStateSet(['aisle_ids' => [(string) $a2->id, (string) $room->id]])
        ->assertSchemaComponentHidden('same_day_warning');
});

test('a task can be cancelled from the dashboard, freeing its aisle', function () {
    $ctx  = stockTakeContext();
    $a1   = stAisle($ctx, 'Aisle 1');
    $st   = stOpen($ctx);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$a1->id], $ctx['keeperA'], null);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(TasksRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->assertCanSeeTableRecords([$task])
        ->callTableAction('cancel', $task);

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_CANCELLED);

    stPlanner()->assignCount($ctx['admin'], $st, [$a1->id], $ctx['keeperB'], null);

    expect(StockTakeTask::count())->toBe(2);
});

test('closing is refused while a task is out, with the reason shown', function () {
    $ctx = stockTakeContext();
    $st  = stOpen($ctx);
    stPlanner()->assignCount($ctx['admin'], $st, [stAisle($ctx, 'Aisle 1')->id], $ctx['keeperA'], null);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(ViewStockTake::class, ['record' => $st->id])
        ->callAction('close')
        ->assertNotified('Not done');

    expect($st->fresh()->isOpen())->toBeTrue();
});

test('an unknown item can be ignored from the dashboard', function () {
    $ctx  = stockTakeContext();
    $st   = stOpen($ctx);
    $item = StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'barcode' => '999', 'name_entered' => 'Mystery Tin', 'created_by' => $ctx['keeperA']->id,
    ]);

    stDashboardAs($ctx, $ctx['admin']);

    Livewire::test(UnknownItems::class, ['record' => $st])->callTableAction('ignore', $item);

    expect($item->fresh()->status)->toBe('ignored');
});

test('storekeepers do not get the Stock Takes screens', function () {
    $ctx = stockTakeContext();
    stDashboardAs($ctx, $ctx['keeperA']);

    expect(StockTakeResource::canAccess())->toBeFalse();
});

test('stock takes of another vendor or branch are not listed', function () {
    $ctx    = stockTakeContext();
    $other  = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);
    $ours   = stOpen($ctx);
    $theirs = stOpen($other);
    $lekki  = stPlanner()->open($ctx['owner'], $branch, ['name' => 'Lekki count', 'start_date' => '2026-11-24', 'end_date' => '2026-11-25']);

    stDashboardAs($ctx, $ctx['owner']);

    Livewire::test(ListStockTakes::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs, $lekki]);
});
