<?php

use App\Filament\Vendor\Pages\BlindCount;
use App\Filament\Vendor\Resources\Products\Pages\ListProducts;
use App\Filament\Vendor\Widgets\BlindCountInProgressWidget;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ActiveStore;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * Step 8: the old Inventory Count behind a per-vendor setting — off for new
 * shops, kept on for the shops that had it.
 */

function stOldCountAs(array $ctx): void
{
    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']->fresh());
    ActiveStore::set($ctx['vendor'], $ctx['owner'], $ctx['store']);
}

test('a new shop starts with the old Inventory Count (GadgetPlug keeps it on for every shop)', function () {
    $vendor = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Brand New '.uniqid()]);

    expect($vendor->fresh()->hasInventoryCount())->toBeTrue();
});

test('shops that exist when it ships keep the old Inventory Count on', function () {
    $migration = require database_path('migrations/2026_11_25_130000_add_inventory_count_enabled_to_vendors_table.php');
    $migration->down();

    $existing = Vendor::create(['user_id' => User::factory()->create()->id, 'name' => 'Old Shop '.uniqid()]);

    $migration->up();

    expect($existing->fresh()->hasInventoryCount())->toBeTrue();
});

test('switched off: the page, its in-progress card and Start Inventory Count are gone', function () {
    $ctx     = stockTakeContext();
    $product = stProduct($ctx);
    $ctx['vendor']->update(['inventory_count_enabled' => false]);
    stOldCountAs($ctx);

    expect(BlindCount::canAccess())->toBeFalse()
        ->and(BlindCountInProgressWidget::canView())->toBeFalse();

    Livewire::test(ListProducts::class)->assertTableActionHidden('start_audit', $product);

    $ctx['vendor']->update(['inventory_count_enabled' => true]);
    stOldCountAs($ctx);

    expect(BlindCount::canAccess())->toBeTrue()
        ->and(BlindCountInProgressWidget::canView())->toBeTrue();

    Livewire::test(ListProducts::class)->assertTableActionVisible('start_audit', $product);
});

test('switched off, the page itself is refused, not only hidden from the menu', function () {
    $ctx = stockTakeContext();
    $ctx['vendor']->update(['inventory_count_enabled' => false]);
    $this->actingAs($ctx['owner']);

    $this->get("/plug/{$ctx['vendor']->slug}/blind-count")->assertRedirect();

    $ctx['vendor']->update(['inventory_count_enabled' => true]);

    $this->get("/plug/{$ctx['vendor']->slug}/blind-count")->assertOk();
});
