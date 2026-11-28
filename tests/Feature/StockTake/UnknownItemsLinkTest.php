<?php

use App\Filament\Vendor\Resources\StockTakes\Widgets\LinkProductPanel;
use App\Filament\Vendor\Resources\StockTakes\Widgets\UnknownItems;
use App\Models\ProductBarcode;
use App\Models\StockTake;
use App\Models\StockTakeUnknownItem;
use App\Services\ActiveStore;
use App\Services\StockTake\UnknownItemMatcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

/**
 * "Not in the system": linking an unknown barcode to a product the shop has.
 * The pop-up opens with what the counter wrote beside the likeliest products,
 * and finds the rest by any words in any order, by SKU or by barcode.
 */

function ulAs(array $ctx): StockTake
{
    test()->actingAs($ctx['admin']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);
    ActiveStore::set($ctx['vendor'], $ctx['admin'], $ctx['store']);

    return stStockTake($ctx);
}

function ulItem(array $ctx, string $name, ?string $barcode = '6151100000001'): StockTakeUnknownItem
{
    return StockTakeUnknownItem::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'barcode' => $barcode, 'name_entered' => $name, 'created_by' => $ctx['keeperA']->id,
    ]);
}

test('the likeliest products for what the counter wrote come first, with price and stock here', function () {
    $ctx  = stockTakeContext();
    ulAs($ctx);
    $peak = stProduct($ctx, 'Peak Evaporated Milk 160g', 12);
    $dano = stProduct($ctx, 'Dano Milk Tin 400g');
    stProduct($ctx, 'Indomie Chicken 70g');
    $item = ulItem($ctx, 'peak milk tin');

    $likely = collect(app(UnknownItemMatcher::class)->likely($item))->keyBy('id');

    expect($likely->keys()->all())->toContain($peak->id, $dano->id)
        ->and($likely)->toHaveCount(2)
        ->and($likely[$peak->id]['price'])->toBe(1500.0)
        ->and($likely[$peak->id]['stock'])->toEqual(12);
});

test('the search finds words in any order, and a barcode a product already has', function () {
    $ctx   = stockTakeContext();
    ulAs($ctx);
    $peak  = stProduct($ctx, 'Peak Evaporated Milk 160g');
    $other = stProduct($ctx, 'Indomie Chicken 70g');
    ProductBarcode::create(['product_id' => $other->id, 'barcode' => '8990001112223']);
    $item  = ulItem($ctx, 'something');
    $m     = app(UnknownItemMatcher::class);

    expect(collect($m->search($item, 'milk peak'))->pluck('id')->all())->toBe([$peak->id])
        ->and(collect($m->search($item, '1112223'))->pluck('id')->all())->toBe([$other->id])
        ->and($m->search($item, '  '))->toBe([]);
});

test('Link to existing opens the pop-up in the browser, without a trip to the server', function () {
    $ctx  = stockTakeContext();
    $st   = ulAs($ctx);
    $item = ulItem($ctx, 'peak milk');

    Livewire::test(UnknownItems::class, ['record' => $st])
        ->assertSeeHtml("gp-link-product', { id: {$item->id} })");
});

test('the pop-up shows what the counter wrote, searches, and links', function () {
    $ctx  = stockTakeContext();
    $st   = ulAs($ctx);
    $peak = stProduct($ctx, 'Peak Evaporated Milk 160g');
    stProduct($ctx, 'Indomie Chicken 70g');
    $item = ulItem($ctx, 'peak milk', '6151100000099');

    Livewire::test(LinkProductPanel::class, ['record' => $st])
        ->call('show', $item->id)
        ->assertSee('The counter wrote')
        ->assertSee('peak milk')
        ->assertSee('6151100000099')
        ->assertSee('Peak Evaporated Milk 160g')
        ->assertDontSee('Indomie Chicken')
        ->set('search', 'indomie')
        ->assertSee('Indomie Chicken')
        ->call('link', $peak->id)
        ->assertNotified('Barcode linked')
        ->assertDispatched('stock-take-updated');

    expect($item->fresh()->status)->not->toBe('open')
        ->and(ProductBarcode::query()->where('product_id', $peak->id)->where('barcode', '6151100000099')->exists()
            || $peak->fresh()->barcode === '6151100000099')->toBeTrue();
});

test('the pop-up will not open another branch\'s barcode', function () {
    $ctx   = stockTakeContext();
    $st    = ulAs($ctx);
    $item  = ulItem($ctx, 'peak milk');
    $item->update(['store_id' => \App\Models\Store::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'Other branch'])->id]);

    Livewire::test(LinkProductPanel::class, ['record' => $st])
        ->call('show', $item->id)
        ->assertSet('itemId', null);
});
