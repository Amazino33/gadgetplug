<?php

use App\Filament\Vendor\Widgets\InventoryTableWidget;
use App\Models\InventoryLedger;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The inventory page listed what a product holds but never how it got there.
// Clicking a row now opens its movement history — what happened, who did it,
// and when — read straight from the inventory ledger.

function movementsContext(): array
{
    $ctx = debtTenderContext();

    test()->actingAs($ctx['owner']);
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
    Filament::setTenant($ctx['vendor']);

    return $ctx;
}

function recordMovement(array $ctx, array $overrides = []): InventoryLedger
{
    return InventoryLedger::create(array_merge([
        'vendor_id'        => $ctx['vendor']->id,
        'store_id'         => $ctx['store']->id,
        'product_id'       => $ctx['product']->id,
        'user_id'          => $ctx['owner']->id,
        'transaction_type' => 'restock',
        'quantity_change'  => 10,
        'reference'        => 'REF-1',
        'description'      => 'Opening delivery',
    ], $overrides));
}

/** The modal body for one product, as the action renders it. */
function movementsHtml(array $ctx, ?Product $product = null): string
{
    $widget = new InventoryTableWidget();

    $method = new ReflectionMethod($widget, 'buildStockMovementsTable');
    $method->setAccessible(true);

    return (string) $method->invoke($widget, $product ?? $ctx['product']);
}

test('a movement shows what happened, who did it and when', function () {
    $ctx = movementsContext();
    recordMovement($ctx);

    $html = movementsHtml($ctx);

    expect($html)->toContain('Restock')                       // how
        // Escaped, because that is what lands in the HTML: a faker name
        // carrying an apostrophe (O'Hara) renders as O&#039;Hara and made this
        // assertion fail at random.
        ->and($html)->toContain(e($ctx['owner']->name))       // who
        ->and($html)->toContain(now()->format('d M Y'))       // when
        ->and($html)->toContain('+10')                        // what changed
        ->and($html)->toContain('Opening delivery');          // why
});

test('stock leaving is shown as a negative, stock arriving as a positive', function () {
    $ctx = movementsContext();
    recordMovement($ctx, ['transaction_type' => 'pos_sale', 'quantity_change' => -3, 'description' => 'Sold at till']);

    $html = movementsHtml($ctx);

    expect($html)->toContain('-3')
        ->and($html)->toContain('POS Sale');
});

test('a movement with nobody behind it reads as System, not as a blank', function () {
    $ctx = movementsContext();
    recordMovement($ctx, ['user_id' => null, 'transaction_type' => 'online_sale', 'quantity_change' => -1]);

    expect(movementsHtml($ctx))->toContain('System');
});

test('newest movements come first', function () {
    $ctx = movementsContext();

    recordMovement($ctx, ['description' => 'The older one'])
        ->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
    recordMovement($ctx, ['description' => 'The newer one']);

    $html = movementsHtml($ctx);

    expect(strpos($html, 'The newer one'))->toBeLessThan(strpos($html, 'The older one'));
});

test('a product that has never moved says so instead of showing an empty table', function () {
    $ctx = movementsContext();

    expect(movementsHtml($ctx))->toContain('No stock movement has been recorded');
});

test('only this product\'s movements are shown', function () {
    $ctx = movementsContext();
    recordMovement($ctx, ['description' => 'Belongs to this one']);

    $other = Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => $ctx['product']->category_id, 'name' => 'Another Widget',
        'price' => 500, 'stock_quantity' => 1, 'status' => 'published',
    ]);
    recordMovement($ctx, ['product_id' => $other->id, 'description' => 'Belongs to the other one']);

    $html = movementsHtml($ctx);

    expect($html)->toContain('Belongs to this one')
        ->and($html)->not->toContain('Belongs to the other one');
});

test('a long history is capped and says how much more there is', function () {
    $ctx = movementsContext();

    for ($i = 0; $i < 105; $i++) {
        recordMovement($ctx, ['description' => "Movement {$i}"]);
    }

    $html = movementsHtml($ctx);

    expect($html)->toContain('most recent 100 of 105');
});

test('the inventory table renders with the movements action available', function () {
    $ctx = movementsContext();
    recordMovement($ctx);

    Livewire::test(InventoryTableWidget::class)
        ->assertOk()
        ->assertTableActionExists('stockMovements');
});
