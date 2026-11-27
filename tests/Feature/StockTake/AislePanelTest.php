<?php

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Filament\Vendor\Resources\Aisles\Pages\EditAisle;
use App\Filament\Vendor\Resources\Aisles\Pages\ListAisles;
use App\Filament\Vendor\Resources\Aisles\RelationManagers\ProductsRelationManager;
use App\Filament\Vendor\Resources\MappingTasks\Pages\ListMappingTasks;
use App\Models\Aisle;
use App\Models\StockTakeTask;
use App\Models\Store;
use App\Models\User;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Step 3: the Aisles screen in the vendor panel, and mapping tasks.
 */

function stPanelAs(array $ctx, User $user, ?Store $store = null): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $store ?? $ctx['store']);
}

test('admins see the branch\'s aisles; a storekeeper does not get the screen', function () {
    $ctx    = stockTakeContext();
    $branch = Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Lekki']);
    $ours   = stAisle($ctx, 'Aisle 1');
    $theirs = stAisle($ctx, 'Lekki aisle', $branch);

    stPanelAs($ctx, $ctx['owner']);

    Livewire::test(ListAisles::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);

    stPanelAs($ctx, $ctx['keeperA']);

    expect(AisleResource::canAccess())->toBeFalse();
});

test('a new aisle belongs to the active branch and goes to the end of the walk', function () {
    $ctx = stockTakeContext();
    stAisle($ctx, 'Aisle 1')->update(['sort_order' => 4]);

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)
        ->callAction('create', ['name' => 'Fridge', 'code' => 'F01', 'is_active' => true])
        ->assertHasNoActionErrors();

    $aisle = Aisle::where('name', 'Fridge')->sole();

    expect($aisle->store_id)->toBe($ctx['store']->id)
        ->and($aisle->vendor_id)->toBe($ctx['vendor']->id)
        ->and($aisle->sort_order)->toBe(5);
});

test('a short code is unique within the branch', function () {
    $ctx = stockTakeContext();
    stAisle($ctx, 'Aisle 1')->update(['code' => 'A01']);

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)
        ->callAction('create', ['name' => 'Another', 'code' => 'A01', 'is_active' => true])
        ->assertHasActionErrors(['code' => 'unique']);
});

test('assigning a mapping task gives one person the selected aisles', function () {
    $ctx = stockTakeContext();
    $a3  = stAisle($ctx, 'Aisle 3');
    $a4  = stAisle($ctx, 'Aisle 4');
    stAisle($ctx, 'Aisle 5');

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)
        ->callTableBulkAction('assign_mapping', [$a3, $a4], ['assigned_to' => $ctx['keeperA']->id, 'due_date' => '2026-11-26'])
        ->assertHasNoTableBulkActionErrors();

    $task = StockTakeTask::sole();

    expect($task->type)->toBe(StockTakeTask::TYPE_MAPPING)
        ->and($task->assigned_to)->toBe($ctx['keeperA']->id)
        ->and($task->store_id)->toBe($ctx['store']->id)
        ->and($task->stock_take_id)->toBeNull()
        ->and($task->due_date->toDateString())->toBe('2026-11-26')
        ->and($task->aisles->pluck('id')->sort()->values()->all())->toBe([$a3->id, $a4->id]);
});

test('a mapping task cannot go to someone from another shop', function () {
    $ctx      = stockTakeContext();
    $stranger = stockTakeContext()['keeperA'];
    $aisle    = stAisle($ctx, 'Aisle 3');

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)
        ->callTableBulkAction('assign_mapping', [$aisle], ['assigned_to' => $stranger->id]);

    expect(StockTakeTask::count())->toBe(0);
});

test('an admin can cancel an open mapping task', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 3');
    $task  = StockTakeTask::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'type' => StockTakeTask::TYPE_MAPPING, 'assigned_to' => $ctx['keeperA']->id, 'created_by' => $ctx['admin']->id,
    ]);
    $task->attachAisles([$aisle->id]);

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListMappingTasks::class)
        ->assertCanSeeTableRecords([$task])
        ->callTableAction('cancel', $task);

    expect($task->fresh()->status)->toBe(StockTakeTask::STATUS_CANCELLED);
});

test('the products on an aisle can be added and removed from the panel', function () {
    $ctx     = stockTakeContext();
    $aisle   = stAisle($ctx, 'Aisle 2');
    $product = stProduct($ctx, 'Milo 400g');

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $aisle, 'pageClass' => EditAisle::class])
        ->callTableAction('attach', data: ['recordId' => $product->id])
        ->assertHasNoTableActionErrors();

    $mapped = $aisle->products()->first();

    expect($mapped->id)->toBe($product->id)
        ->and($mapped->pivot->source)->toBe('mapping')
        ->and($mapped->pivot->mapped_by)->toBe($ctx['admin']->id);

    Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $aisle, 'pageClass' => EditAisle::class])
        ->callTableAction('detach', $product);

    expect($aisle->products()->count())->toBe(0);
});

test('an aisle is taken out of use rather than deleted', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 2');

    stPanelAs($ctx, $ctx['admin']);

    Livewire::test(ListAisles::class)->callTableAction('toggle_active', $aisle);

    expect($aisle->fresh()->is_active)->toBeFalse()
        ->and(AisleResource::canDelete($aisle))->toBeFalse();
});

test('aisles and mapping tasks of another vendor never show on this shop screens', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    $ours   = stAisle($ctx, 'Ours');
    $theirs = stAisle($other, 'Theirs');
    $theirTask = StockTakeTask::create([
        'vendor_id' => $other['vendor']->id, 'store_id' => $other['store']->id,
        'type' => StockTakeTask::TYPE_MAPPING, 'assigned_to' => $other['keeperA']->id, 'created_by' => $other['admin']->id,
    ]);

    stPanelAs($ctx, $ctx['owner']);

    Livewire::test(ListAisles::class)
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::test(ListMappingTasks::class)
        ->assertCanNotSeeTableRecords([$theirTask]);
});
