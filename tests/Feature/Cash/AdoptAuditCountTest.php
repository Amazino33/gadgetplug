<?php

use App\Actions\Inventory\AdoptAuditCountAction;
use App\Models\PhysicalStockCount;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/CloseHelpers.php';

uses(RefreshDatabase::class);

/**
 * Stock is counted two ways in this codebase, and the one people actually use
 * is the two-person blind count on the Inventory Count page. A close needs one
 * agreed figure per product, so a finished count is copied into a settlement
 * count rather than read through in two shapes.
 */

function adopt(array $ctx, $session)
{
    return app(AdoptAuditCountAction::class)->execute($session, $ctx['owner']);
}

test('a finished inventory count becomes a count the close can use', function () {
    $ctx = closeContext(onShelf: 10);

    $session = closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10],
    ]);

    $count = adopt($ctx, $session);

    expect($count)->toBeInstanceOf(PhysicalStockCount::class)
        ->and($count->store_id)->toBe($ctx['store']->id)
        ->and($count->blind_count_session_id)->toBe($session->id)
        ->and($count->lines)->toHaveCount(1);

    $line = $count->lines->first();

    expect($line->counted_quantity)->toBe(7)
        // The baseline the blind count froze, carried over rather than re-read,
        // so the variance it found is the variance it still reports.
        ->and($line->system_quantity)->toBe(10)
        // Prices are captured on adoption, since the blind count never records
        // either one.
        ->and((float) $line->unit_cost)->toBe(60000.00)
        ->and((float) $line->unit_price)->toBe(100000.00);
});

test('adopting the same count twice gives back the same one', function () {
    $ctx = closeContext(onShelf: 10);

    $session = closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10],
    ]);

    $first  = adopt($ctx, $session);
    $second = adopt($ctx, $session);

    // A second copy would compete with the first for the same period, and the
    // chain would have two different answers to what the shelf held.
    expect($second->id)->toBe($first->id)
        ->and(PhysicalStockCount::where('store_id', $ctx['store']->id)->count())->toBe(1);
});

test("a manager's override outranks what either counter wrote down", function () {
    $ctx = closeContext(onShelf: 10);

    $session = closeAuditSession($ctx, [
        $ctx['product']->id => [
            'counted'  => 7,
            'system'   => 10,
            'status'   => 'resolved_by_override',
            'override' => 5,
        ],
    ]);

    // Two people disagreed and somebody with authority settled it. That
    // decision is the figure, not either count behind it.
    expect(adopt($ctx, $session)->lines->first()->counted_quantity)->toBe(5);
});

test('a line the two counters never agreed on is left out', function () {
    $ctx = closeContext(onShelf: 10);

    $other = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id,
        'store_id'  => $ctx['store']->id,
        'category_id' => $ctx['product']->category_id,
        'name' => 'Disputed Phone', 'price' => 50000, 'cost_price' => 30000,
        'stock_quantity' => 4, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    $session = closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10],
        $other->id          => ['counted' => 3, 'system' => 4, 'status' => 'discrepancy'],
    ]);

    $count = adopt($ctx, $session);

    // Putting one counter's number in would assert something nobody agreed, and
    // every later period's opening would inherit it.
    expect($count->lines)->toHaveCount(1)
        ->and($count->lines->first()->product_id)->toBe($ctx['product']->id)
        ->and($count->note)->toContain('1 of 2 lines agreed');
});

test('an unfinished inventory count cannot close anything', function () {
    $ctx = closeContext(onShelf: 10);

    $session = closeAuditSession(
        $ctx,
        [$ctx['product']->id => ['counted' => 7, 'system' => 10]],
        ['status' => 'a_counting'],
    );

    adopt($ctx, $session);
})->throws(RuntimeException::class, 'Only a finished inventory count');

test('a count where nothing was agreed gives nothing to close on', function () {
    $ctx = closeContext(onShelf: 10);

    $session = closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10, 'status' => 'discrepancy'],
    ]);

    adopt($ctx, $session);
})->throws(RuntimeException::class, 'None of the lines in that count were agreed');

test('an adopted count closes a period and carries forward like any other', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = adopt($ctx, closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 10, 'system' => 10],
    ]));

    $closing = adopt($ctx, closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10],
    ]));

    $close = closePeriod($ctx, $closing, openingCount: $opening);

    expect($close->opening_count_id)->toBe($opening->id)
        ->and($close->openingQuantities()[$ctx['product']->id])->toBe(10)
        ->and($close->closingQuantities()[$ctx['product']->id])->toBe(7);
});

test('the variance reads an adopted count the same as a hand-entered one', function () {
    $ctx = closeContext(onShelf: 10);

    $opening = adopt($ctx, closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 10, 'system' => 10],
    ]));

    $closing = adopt($ctx, closeAuditSession($ctx, [
        $ctx['product']->id => ['counted' => 7, 'system' => 10],
    ]));

    $view = closeBalance($ctx, $closing, $opening);

    expect($view['count_variance']['units'])->toBe(3)
        ->and($view['count_variance']['at_selling'])->toBe(300000.0)
        // And it values at cost and retail exactly as a hand-entered count does.
        ->and($view['stock_movement']['opening_value'])->toBe(600000.0)
        ->and($view['stock_movement']['closing_selling'])->toBe(700000.0);
});
