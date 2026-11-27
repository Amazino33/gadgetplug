<?php

use App\Filament\Vendor\Resources\Aisles\AisleResource;
use App\Filament\Vendor\Resources\StockTakes\StockTakeResource;
use App\Models\PosTillSyncState;
use App\Models\ProductUnit;
use App\Models\StockTakeEntry;
use App\Models\StockTakeUnknownItem;
use App\Services\ActiveStore;
use App\Services\StockTake\StockTakeAccess;
use App\Services\StockTake\TillSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/StockTakePlanningHelpers.php';

uses(RefreshDatabase::class);

/**
 * Additions before step 6: saves that can be retried safely, the per-vendor
 * switch, and tills reporting what they still hold unsynced.
 */

test('a save whose answer was lost, retried, is answered again and counted once', function () {
    $ctx   = stockTakeContext();
    $coke  = stProduct($ctx, 'Coke');
    $piece = ProductUnit::where('product_id', $coke->id)->where('is_base', true)->value('id');
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $url   = "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}/entries";
    $saveId = (string) Str::uuid();
    $body  = ['aisle_id' => $aisle->id, 'product_id' => $coke->id, 'lines' => [['unit_id' => $piece, 'qty' => 12]], 'save_id' => $saveId];

    $this->actingAs($ctx['keeperA']);

    // The first try lands; its answer never reaches the phone.
    $this->postJson($url, $body)->assertCreated();

    // Retry: same id. No Add-or-Replace question, no second 12.
    $this->postJson($url, $body)->assertCreated()->assertJsonPath('entry.counted', 12);

    expect(StockTakeEntry::sole()->counted_qty_base)->toBe(12);

    // A retried Add after a lost answer adds once too.
    $add = [...$body, 'mode' => 'add', 'save_id' => (string) Str::uuid(), 'lines' => [['unit_id' => $piece, 'qty' => 5]]];
    $this->postJson($url, $add)->assertJsonPath('entry.counted', 17);
    $this->postJson($url, $add)->assertJsonPath('entry.counted', 17);

    // Without an id, a second save still has to say Add or Replace.
    $this->postJson($url, [...$body, 'save_id' => null])->assertStatus(422);
    expect(StockTakeEntry::sole()->counted_qty_base)->toBe(17);
});

test('an unknown item and a hand-in, retried, happen once', function () {
    $ctx   = stockTakeContext();
    $aisle = stAisle($ctx, 'Drinks');
    $task  = stPlanner()->assignCount($ctx['admin'], stOpen($ctx), [$aisle->id], $ctx['keeperA'], null);
    $base  = "/stock-take/{$ctx['vendor']->slug}/api/tasks/{$task->id}";
    $item  = ['aisle_id' => $aisle->id, 'name' => 'Mystery', 'save_id' => (string) Str::uuid()];

    $this->actingAs($ctx['keeperA']);
    $this->postJson("{$base}/unknown-items", $item)->assertCreated();
    $this->postJson("{$base}/unknown-items", $item)->assertCreated();
    expect(StockTakeUnknownItem::count())->toBe(1);

    $this->postJson("{$base}/submit", ['device' => 'test-phone'])->assertOk();
    $this->postJson("{$base}/submit", ['device' => 'test-phone'])->assertOk()->assertJsonPath('status', 'submitted');
});

test('switched off, Stock Take is hidden and refused for everyone in the shop', function () {
    $ctx = stockTakeContext();
    $ctx['vendor']->update(['stock_take_enabled' => false]);
    $slug = $ctx['vendor']->slug;

    $this->actingAs($ctx['owner'])->getJson("/stock-take/{$slug}/api/home")->assertNotFound();
    $this->actingAs($ctx['keeperA'])->get("/stock-take/{$slug}")->assertNotFound();

    expect(StockTakeAccess::canManage($ctx['owner'], $ctx['vendor']->id, $ctx['store']->id))->toBeFalse();

    $this->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']->fresh());
    ActiveStore::set($ctx['vendor'], $ctx['owner'], $ctx['store']);

    expect(StockTakeResource::canAccess())->toBeFalse()
        ->and(AisleResource::canAccess())->toBeFalse();

    // On again: back as it was.
    $ctx['vendor']->update(['stock_take_enabled' => true]);
    Filament::setTenant($ctx['vendor']->fresh());

    expect(StockTakeResource::canAccess())->toBeTrue();
    $this->getJson("/stock-take/{$slug}/api/home")->assertOk();
});

test('Stock Take is off for a new shop until it is switched on', function () {
    $vendor = App\Models\Vendor::create(['user_id' => App\Models\User::factory()->create()->id, 'name' => 'New Shop '.uniqid()]);

    expect($vendor->fresh()->hasStockTake())->toBeFalse();
});

test('a till reports what it still holds unsynced, and clears it once synced', function () {
    $ctx = stockTakeContext();
    Sanctum::actingAs($ctx['keeperA'], ['pos']);

    $this->postJson('/api/pos/till-state', [
        'vendor_id' => $ctx['vendor']->id, 'device_id' => 'till-abc',
        'pending' => [['store_id' => $ctx['store']->id, 'count' => 3, 'oldest_at' => '2026-11-24T09:55:00Z']],
    ])->assertOk();

    $waiting = TillSync::pendingAt($ctx['store']->id);

    expect($waiting)->toHaveCount(1)
        ->and($waiting->first()->pending_sales)->toBe(3)
        ->and($waiting->first()->user_id)->toBe($ctx['keeperA']->id)
        ->and($waiting->first()->oldest_pending_at->toDateTimeString())->toBe('2026-11-24 09:55:00');

    $this->postJson('/api/pos/till-state', ['vendor_id' => $ctx['vendor']->id, 'device_id' => 'till-abc', 'pending' => []])->assertOk();

    expect(TillSync::pendingAt($ctx['store']->id))->toHaveCount(0)
        ->and(PosTillSyncState::count())->toBe(1);
});

test('a till cannot report for another shop or a branch that is not the shop\'s', function () {
    $ctx   = stockTakeContext();
    $other = stockTakeContext();
    Sanctum::actingAs($ctx['keeperA'], ['pos']);

    $this->postJson('/api/pos/till-state', ['vendor_id' => $other['vendor']->id, 'device_id' => 'x', 'pending' => []])->assertForbidden();

    $this->postJson('/api/pos/till-state', [
        'vendor_id' => $ctx['vendor']->id, 'device_id' => 'x',
        'pending' => [['store_id' => $other['store']->id, 'count' => 2]],
    ])->assertOk();

    expect(TillSync::pendingAt($other['store']->id))->toHaveCount(0);
});
