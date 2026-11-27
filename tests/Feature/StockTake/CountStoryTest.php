<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Filament\Vendor\Resources\StockTakes\Pages\ViewStockTake;
use App\Filament\Vendor\Resources\StockTakes\RelationManagers\ResultsRelationManager;
use App\Models\User;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeStory;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';
require_once __DIR__ . '/StockTakeApprovalHelpers.php';

uses(RefreshDatabase::class);

/**
 * The story behind a count, for the manager approving it: what the books said
 * when it was counted, and who sold what before (and after).
 */

function storySale(array $ctx, $product, User $cashier, int $qty, string $lagosTime, string $ref): void
{
    app(AdjustStockAction::class)->execute(
        productId: $product->id,
        quantityChanged: -$qty,
        transactionType: 'pos_sale',
        userId: $cashier->id,
        reference: $ref,
        store: $ctx['store']->id,
        occurredAt: Carbon::parse("2026-11-27 {$lagosTime}", 'Africa/Lagos')->utc(),
    );
}

/** 25 on the shelf this morning; Chika sells 2, Emeka 3 in two sales; Ada counts 18 at 10:42; Chika sells 1 after. */
function storyScenario(): array
{
    Carbon::setTestNow(Carbon::parse('2026-11-26 15:00', 'Africa/Lagos'));
    [$ctx, $milk, $aisle, $st, $task] = stOneProduct(['books' => 25]);

    $chika = User::factory()->create(['name' => 'Chika']);
    $emeka = User::factory()->create(['name' => 'Emeka']);

    Carbon::setTestNow(Carbon::parse('2026-11-27 09:00', 'Africa/Lagos'));
    storySale($ctx, $milk, $chika, 2, '09:02', 'S-1043');
    storySale($ctx, $milk, $emeka, 1, '09:40', 'S-1044');
    storySale($ctx, $milk, $emeka, 2, '10:15', 'S-1047');

    Carbon::setTestNow(Carbon::parse('2026-11-27 10:42', 'Africa/Lagos'));
    stCountAndHandIn($ctx, $task, $ctx['keeperA'], [[$aisle, $milk, '18']]);

    Carbon::setTestNow(Carbon::parse('2026-11-27 10:50', 'Africa/Lagos'));
    storySale($ctx, $milk, $chika, 1, '10:50', 'S-1052');

    return [$ctx, $milk, $st, $task, stResultFor($task, $milk)];
}

afterEach(fn () => Carbon::setTestNow());

test('the books at the count, how they got there, and who sold it', function () {
    [$ctx, $milk, $st, $task, $result] = storyScenario();

    $s = app(StockTakeStory::class)->for($result);

    expect((float) $s['opening'])->toBe(25.0)
        ->and((float) $s['books'])->toBe(20.0)
        ->and((float) $s['counted'])->toBe(18.0)
        ->and((float) $s['difference'])->toBe(-2.0)
        ->and($s['counted_by'])->toBe($ctx['keeperA']->name);

    // Before the count: three till sales, in order, with who and the running books.
    expect(collect($s['before'])->map(fn ($r) => [$r['by'], (float) $r['qty'], (float) $r['balance']])->all())
        ->toBe([['Chika', -2.0, 23.0], ['Emeka', -1.0, 22.0], ['Emeka', -2.0, 20.0]]);

    // Who sold it, in a line: Emeka 3 in two sales, Chika 2 in one.
    expect($s['sellers'])->toBe([
        ['name' => 'Emeka', 'qty' => 3, 'sales' => 2],
        ['name' => 'Chika', 'qty' => 2, 'sales' => 1],
    ]);

    // After the count: shown, never compared.
    expect($s['after'])->toHaveCount(1)
        ->and($s['after'][0]['by'])->toBe('Chika')
        ->and((float) $s['now'])->toBe(19.0);
});

test('the manager opens it from the numbers on the approval list', function () {
    [$ctx, $milk, $st, $task, $result] = storyScenario();

    test()->actingAs($ctx['admin']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $ctx['admin'], $ctx['store']);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $st, 'pageClass' => ViewStockTake::class])
        ->mountTableAction('story', $result)
        ->assertSet('mountedActions.0.name', 'story')
        ->assertSet('mountedActions.0.context.recordKey', (string) $result->id);

    $html = view('filament.vendor.stock-take.count-story', ['s' => app(StockTakeStory::class)->for($result)])->render();

    expect($html)->toContain('The books said <strong>20</strong>')
        ->toContain('2 missing')
        ->toContain('Emeka 3 (2 sales), Chika 2 (1 sale)')
        ->toContain('Sold at the till')
        ->toContain('S-1043')
        ->toContain('After the count')
        ->toContain('On the books now')
        ->not->toContain('₦');
});
