<?php

use App\Filament\Vendor\Resources\StockTakes\Pages\ViewStockTake;
use App\Filament\Vendor\Resources\StockTakes\RelationManagers\ResultsRelationManager;
use App\Filament\Vendor\Resources\StockTakes\Widgets\CountStoryPanel;
use App\Filament\Vendor\Resources\StockTakes\Widgets\UnknownItems;
use App\Models\StockTakeResult;
use App\Models\StockTakeUnknownItem;
use App\Models\User;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

/**
 * Ticking several results and deciding them at once, ignoring several unknown
 * barcodes at once, and the "what happened" panel that opens at once.
 */

function bdAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['store']);
}

/** Two products on one aisle, both counted and handed in. */
function bdTwoCounted(): array
{
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $sugar = stProduct($ctx, 'Dangote Sugar', 30);
    stMap($ctx, $aisle, $sugar);
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48], [$aisle, $sugar, 31]]);

    return [$ctx, $st, $task, stResultFor($task, $milk), stResultFor($task, $sugar), $milk, $sugar];
}

test('ticked results are approved together, each correcting its stock', function () {
    [$ctx, $st, $task, $milkResult, $sugarResult, $milk, $sugar] = bdTwoCounted();
    bdAs($ctx, $ctx['owner']);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->callTableBulkAction('approve_selected', [$milkResult, $sugarResult])
        ->assertNotified('2 approved');

    expect($milkResult->fresh()->status)->toBe(StockTakeResult::STATUS_APPROVED)
        ->and($sugarResult->fresh()->status)->toBe(StockTakeResult::STATUS_APPROVED)
        ->and(stShelf($ctx, $milk))->toBe(48)
        ->and(stShelf($ctx, $sugar))->toBe(31);
});

test('ticked results are rejected together, and stock is left alone', function () {
    [$ctx, $st, $task, $milkResult, $sugarResult, $milk] = bdTwoCounted();
    bdAs($ctx, $ctx['owner']);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->callTableBulkAction('reject_selected', [$milkResult, $sugarResult])
        ->assertNotified('2 rejected');

    expect($milkResult->fresh()->status)->toBe(StockTakeResult::STATUS_REJECTED)
        ->and(stShelf($ctx, $milk))->toBe(50);
});

test('a bulk approve leaves what this person counted, for someone else', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['admin']->givePermissionTo('perform_stock_takes');
    $task->update(['assigned_to' => $ctx['admin']->id]);
    stCountAndHandIn($ctx, $task, $ctx['admin'], [[$aisle, $milk, 48]]);
    $result = stResultFor($task, $milk);
    bdAs($ctx, $ctx['admin']);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->callTableBulkAction('approve_selected', [$result])
        ->assertNotified('0 approved');

    expect($result->fresh()->status)->toBe(StockTakeResult::STATUS_PENDING);
});

test('drawing the results list asks for the entries once, not once a row', function () {
    [$ctx, $st] = bdTwoCounted();
    bdAs($ctx, $ctx['owner']);

    $entryQueries = 0;
    DB::listen(function ($q) use (&$entryQueries) {
        if (str_contains($q->sql, 'from "stock_take_entries"') || str_contains($q->sql, 'from `stock_take_entries`')) {
            $entryQueries++;
        }
    });

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->set('activeTab', 'all');

    // Two draws (opening, then the All tab) of two rows, each needing aisles,
    // packs, flags and who counted it, on screen and on the phone card: one
    // query a draw for the entries, and one a row for who counted it.
    expect($entryQueries)->toBeLessThanOrEqual(6);
});

test('the numbers open the what-happened panel, which loads only that count', function () {
    [$ctx, $st, $task, $milkResult] = bdTwoCounted();
    bdAs($ctx, $ctx['owner']);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->set('activeTab', 'all')
        ->assertSeeHtml("gp-count-story', { id: {$milkResult->id} })");

    Livewire::test(CountStoryPanel::class, ['record' => $st])
        ->assertSee('What happened')
        ->call('show', $milkResult->id)
        ->assertSet('resultId', $milkResult->id)
        ->assertSee('The books said');

    // Another stock take's count is not shown here.
    $st->update(['status' => 'closed', 'open_key' => null]);
    $other = stStockTake($ctx, ['name' => 'Another']);
    Livewire::test(CountStoryPanel::class, ['record' => $other])
        ->call('show', $milkResult->id)
        ->assertSet('resultId', null);
});

test('ticked unknown barcodes are set aside together', function () {
    $ctx = stockTakeContext();
    $st  = stStockTake($ctx);
    $items = collect(['Mystery Tin', 'Blue Box'])->map(fn ($name, $i) => StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'barcode' => '99900' . $i, 'name_entered' => $name, 'created_by' => $ctx['keeperA']->id,
    ]));
    bdAs($ctx, $ctx['admin']);

    Livewire::test(UnknownItems::class, ['record' => $st])
        ->callTableBulkAction('ignore_selected', $items->all())
        ->assertNotified('2 set aside');

    expect($items->map(fn ($i) => $i->fresh()->status)->unique()->all())->toBe(['ignored']);
});
