<?php

use App\Filament\Vendor\Resources\AuditSessions\Pages\ManageAuditSessions;
use App\Filament\Vendor\Resources\CountSessions\Pages\ListCountSessions;
use App\Filament\Vendor\Resources\CountSessions\Widgets\StockTakeAudits;
use App\Filament\Vendor\Resources\StockTakes\Pages\ViewStockTake;
use App\Filament\Vendor\Resources\StockTakes\RelationManagers\ResultsRelationManager;
use App\Filament\Vendor\Resources\StockTakes\Widgets\StockTakeTotals;
use App\Filament\Vendor\Resources\StockTakes\Widgets\TillSyncWarning;
use App\Models\AuditSession;
use App\Models\PosTillSyncState;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeApprover;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

afterEach(fn () => Illuminate\Support\Carbon::setTestNow());

/**
 * Step 6 screens: the approval table, totals, the till warning, and the
 * Stock Take rows on Inventory → Audit Sessions.
 */

function stApprovalAs(array $ctx, User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $user, $ctx['store']);
}

function stResults(array $ctx, $st)
{
    return Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class]);
}

test('a counted product is approved from the results table, correcting stock by the variance', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    Illuminate\Support\Carbon::setTestNow('2026-11-24 10:00:00');
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);
    Illuminate\Support\Carbon::setTestNow('2026-11-24 13:00:00');
    stSell($ctx, $milk, 10);
    Illuminate\Support\Carbon::setTestNow('2026-11-24 16:00:00');
    $result = stResultFor($task, $milk);

    stApprovalAs($ctx, $ctx['owner']);

    stResults($ctx, $st)
        ->assertCanSeeTableRecords([$result])
        ->callTableAction('approve', $result)
        ->assertNotified('Approved — stock corrected');

    expect(stShelf($ctx, $milk))->toBe(38);
});

test('the counter does not get Approve on what they counted', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    setPermissionsTeamId($ctx['vendor']->id);
    $ctx['admin']->givePermissionTo('perform_stock_takes');
    $task->update(['assigned_to' => $ctx['admin']->id]);
    stCountAndHandIn($ctx, $task, $ctx['admin'], [[$aisle, $milk, 48]]);
    $result = stResultFor($task, $milk);

    stApprovalAs($ctx, $ctx['admin']);

    stResults($ctx, $st)
        ->assertTableActionHidden('approve', $result)
        ->assertTableActionHidden('reject', $result);
});

test('the owner who counted must give a note to approve', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    $task->update(['assigned_to' => $ctx['owner']->id]);
    stCountAndHandIn($ctx, $task, $ctx['owner'], [[$aisle, $milk, 48]]);
    $result = stResultFor($task, $milk);

    stApprovalAs($ctx, $ctx['owner']);

    stResults($ctx, $st)
        ->callTableAction('approve', $result, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    stResults($ctx, $st)->callTableAction('approve', $result, ['note' => 'Only me in the shop today.']);

    expect(AuditSession::sole()->note)->toBe('Only me in the shop today.');
});

test('send to recount offers only people who did not count it, then shows the pair', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 30]]);
    $original = stResultFor($task, $milk);

    stApprovalAs($ctx, $ctx['admin']);

    stResults($ctx, $st)
        ->mountTableAction('recount', $original)
        ->assertTableActionDataSet(['assigned_to' => null])
        ->callMountedTableAction(['assigned_to' => $ctx['keeperA']->id])
        ->assertHasTableActionErrors(['assigned_to']);

    stResults($ctx, $st)->callTableAction('recount', $original, ['assigned_to' => $ctx['keeperB']->id, 'due_date' => '2026-11-26']);

    $recount = StockTakeTask::where('type', 'recount')->sole();
    stCountAndHandIn($ctx, $recount, $ctx['keeperB'], [[$aisle, $milk, 49]]);
    $second = stResultFor($recount, $milk);

    stResults($ctx, $st)
        ->assertCanSeeTableRecords([$second])
        ->assertSee('Original by ' . $ctx['keeperA']->name . ': 30');
});

test('approve all not flagged approves only those within the thresholds', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $ok    = stProduct($ctx, 'Within', 100);
    $off   = stProduct($ctx, 'Way off', 10);
    stMap($ctx, $aisle, $ok, $off);
    $st   = stOpen($ctx);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $ok, 99], [$aisle, $off, 2]]);

    stApprovalAs($ctx, $ctx['admin']);

    stResults($ctx, $st)->callTableAction('approve_unflagged')->assertNotified('1 approved');

    expect(stResultFor($task, $ok)->status)->toBe('approved')
        ->and(stResultFor($task, $off)->status)->toBe('pending');
});

test('totals show shortage, surplus and net for what is waiting', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Aisle 1');
    $short = stProduct($ctx, 'Short', 10); $short->update(['cost_price' => 1000]);
    $over  = stProduct($ctx, 'Over', 10);  $over->update(['cost_price' => 500]);
    stMap($ctx, $aisle, $short, $over);
    $st   = stOpen($ctx);
    $task = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $short, 7], [$aisle, $over, 12]]);

    stApprovalAs($ctx, $ctx['owner']);

    Livewire::test(StockTakeTotals::class, ['record' => $st])
        ->assertSee('₦3,000.00')   // shortage: 3 × 1,000
        ->assertSee('₦1,000.00')   // surplus: 2 × 500
        ->assertSee('−₦2,000.00'); // net
});

test('the approval screen warns while a till at the branch holds unsynced sales', function () {
    [$ctx, , , $st] = stOneProduct();

    stApprovalAs($ctx, $ctx['admin']);

    Livewire::test(TillSyncWarning::class, ['record' => $st])->assertDontSee('have not reached the server');

    PosTillSyncState::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id, 'device_id' => 'till-1',
        'user_id' => $ctx['keeperA']->id, 'pending_sales' => 4, 'oldest_pending_at' => now()->subHour(), 'reported_at' => now(),
    ]);

    Livewire::test(TillSyncWarning::class, ['record' => $st])
        ->assertSee('have not reached the server')
        ->assertSee($ctx['keeperA']->name . ': 4 sales');
});

test('an approved stock take shows on Audit Sessions and opens its audit lines', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);
    app(StockTakeApprover::class)->approve($ctx['admin'], stResultFor($task, $milk));
    $line = AuditSession::sole();

    stApprovalAs($ctx, $ctx['owner']);

    Livewire::test(ListCountSessions::class)->assertOk();

    Livewire::test(StockTakeAudits::class)
        ->assertCanSeeTableRecords([$task])
        ->assertSee($st->reference)
        ->assertSee('₦2,000.00');

    Livewire::test(ManageAuditSessions::class)
        ->filterTable('stock_take_task', $task->id)
        ->assertCanSeeTableRecords([$line]);
});

test('a stock take line on Audit Sessions offers no action that would correct stock again', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 48]]);
    app(StockTakeApprover::class)->approve($ctx['admin'], stResultFor($task, $milk));
    $line = AuditSession::sole();

    stApprovalAs($ctx, $ctx['owner']);

    Livewire::test(ManageAuditSessions::class)
        ->assertTableActionHidden('manager_override', $line)
        ->assertTableActionHidden('verify_count', $line)
        ->callTableBulkAction('accept_counted', [$line], ['reason_code' => 'Other']);

    expect(stShelf($ctx, $milk))->toBe(48);
});

test('a result the automatic re-check could not fix shows a badge and the Re-check button', function () {
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct();
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, 50]]);
    app(StockTakeApprover::class)->approve($ctx['admin'], stResultFor($task, $milk));
    $result = stResultFor($task, $milk);

    stApprovalAs($ctx, $ctx['admin']);

    stResults($ctx, $st)->set('tableFilters.status.value', 'approved')->assertTableActionHidden('recheck', $result);
    Livewire::test(App\Filament\Vendor\Resources\StockTakes\Widgets\StockTakeProgress::class, ['record' => $st])->assertDontSee('Need re-check');

    $result->update(['recheck_needed_at' => now()]);

    stResults($ctx, $st)->set('tableFilters.status.value', 'approved')->assertTableActionVisible('recheck', $result);
    Livewire::test(App\Filament\Vendor\Resources\StockTakes\Widgets\StockTakeProgress::class, ['record' => $st])->assertSee('Need re-check');

    expect(App\Filament\Vendor\Resources\StockTakes\StockTakeResource::getNavigationBadge())->toBe('1');
});
