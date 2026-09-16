<?php

use App\Actions\Cash\GenerateSettlementStatementAction;
use App\Actions\Inventory\RecordPhysicalCountAction;
use App\Models\PhysicalStockCount;
use App\Models\SettlementResolution;
use App\Models\StoreSettlementStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/Helpers.php';

uses(RefreshDatabase::class);

function statementContext(): array
{
    $vendor  = cashVendor();
    $store   = $vendor->defaultStore;
    $owner   = User::find($vendor->user_id);
    $cashier = User::factory()->create();

    return compact('vendor', 'store', 'owner', 'cashier');
}

function generateStatement(array $ctx): StoreSettlementStatement
{
    return app(GenerateSettlementStatementAction::class)->execute(
        generatedBy: $ctx['owner'],
        store:       $ctx['store'],
        from:        now()->subMonth(),
        to:          now()->addDay(),
    );
}

test('a statement records who generated it and when', function () {
    $ctx = statementContext();
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $statement = generateStatement($ctx);

    expect($statement->generated_by)->toBe($ctx['owner']->id)
        ->and($statement->generated_at)->not->toBeNull()
        ->and($statement->reference)->toStartWith('GP-STMT-')
        ->and((float) $statement->expected_cash)->toBe(50000.00);
});

test('the frozen figures do not move when later sales land', function () {
    $ctx = statementContext();
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $statement = generateStatement($ctx);

    // More trade happens after the conversation.
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 90000]);

    // The paper both people were looking at still says what it said.
    // toEqual, not toBe: the payload is JSON, and a round trip through it
    // renders 50000.0 as an int.
    expect($statement->fresh()->figure('cash.expected'))->toEqual(50000);

    // A fresh run over the same dates shows where things now stand.
    expect(generateStatement($ctx)->figure('cash.expected'))->toEqual(140000);
});

test('a statement cannot be edited or deleted after generation', function () {
    $ctx = statementContext();
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $statement = generateStatement($ctx);

    // A value that genuinely differs — setting a column to what it already
    // holds is not a change, so nothing would fire.
    expect(fn () => $statement->update(['true_shortage' => 12345]))
        ->toThrow(LogicException::class);

    expect(fn () => $statement->update(['payload' => ['tampered' => true]]))
        ->toThrow(LogicException::class);

    expect(fn () => $statement->delete())->toThrow(LogicException::class);

    expect((float) $statement->fresh()->expected_cash)->toBe(50000.00);
});

test('what was agreed is appended beside the statement, never into it', function () {
    $ctx = statementContext();
    cashSale($ctx['vendor'], $ctx['store'], $ctx['cashier']->id, ['total' => 50000]);

    $statement = generateStatement($ctx);

    SettlementResolution::create([
        'store_settlement_statement_id' => $statement->id,
        'recorded_by' => $ctx['owner']->id,
        'outcome'     => SettlementResolution::OUTCOME_REPAYMENT_PLAN,
        'concerns'    => 'true_shortage',
        'amount'      => 5000,
        'note'        => 'Agreed 2,500 a week for two weeks',
    ]);

    // A second conversation about the same statement does not erase the first.
    SettlementResolution::create([
        'store_settlement_statement_id' => $statement->id,
        'recorded_by' => $ctx['owner']->id,
        'outcome'     => SettlementResolution::OUTCOME_RESOLVED_NO_ISSUE,
        'concerns'    => 'disputed',
        'note'        => 'Envelope turned up in the safe',
    ]);

    expect($statement->fresh()->resolutions)->toHaveCount(2)
        ->and($statement->fresh()->figure('cash.expected'))->toEqual(50000);
});

test('a resolution has to say what was agreed', function () {
    $ctx = statementContext();
    $statement = generateStatement($ctx);

    expect(fn () => SettlementResolution::create([
        'store_settlement_statement_id' => $statement->id,
        'recorded_by' => $ctx['owner']->id,
        'outcome'     => SettlementResolution::OUTCOME_RESOLVED_NO_ISSUE,
        'note'        => '',
    ]))->toThrow(LogicException::class);
});

test('a statement says plainly when no stock was counted', function () {
    $ctx = statementContext();

    expect(generateStatement($ctx)->figure('stock.counted'))->toBeFalse();
});

test('a physical count is compared against what the system believed at that moment', function () {
    $ctx = statementContext();

    $product = App\Models\Product::create([
        'vendor_id'   => $ctx['vendor']->id,
        'store_id'    => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name'        => 'Counted Widget',
        'price'       => 10000,
        'cost_price'  => 6000,
        'stock_quantity' => 20,
        'reserved_stock' => 0,
        'status'      => 'published',
    ]);

    App\Models\ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
        ['quantity' => 20, 'reserved' => 0],
    );

    // Twenty on the books, seventeen on the shelf.
    $count = app(RecordPhysicalCountAction::class)->execute(
        countedBy:   $ctx['owner'],
        store:       $ctx['store'],
        periodStart: now()->subMonth(),
        periodEnd:   now(),
        counts:      [$product->id => 17],
    );

    $variance = $count->variance();

    expect($variance['units'])->toBe(3)
        ->and($variance['value'])->toBe(18000.0)
        ->and($variance['lines_short'])->toBe(1);

    // And the statement carries it, because cash alone could never have seen it.
    expect(generateStatement($ctx)->figure('stock.variance.units'))->toBe(3);
});

test('a count is evidence and does not quietly correct the stock it disagrees with', function () {
    $ctx = statementContext();

    $product = App\Models\Product::create([
        'vendor_id'   => $ctx['vendor']->id,
        'store_id'    => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name'        => 'Untouched Widget',
        'price'       => 5000, 'cost_price' => 3000,
        'stock_quantity' => 10, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    App\Models\ProductStoreStock::updateOrCreate(
        ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
        ['quantity' => 10, 'reserved' => 0],
    );

    app(RecordPhysicalCountAction::class)->execute(
        countedBy: $ctx['owner'], store: $ctx['store'],
        periodStart: now()->subWeek(), periodEnd: now(),
        counts: [$product->id => 4],
    );

    // Adjusting here would erase the very gap the count just found.
    expect(App\Models\ProductStoreStock::where('product_id', $product->id)
        ->where('store_id', $ctx['store']->id)->value('quantity'))->toBe(10);
});

test('a count names the products that did not add up, and what they would have sold for', function () {
    $ctx = statementContext();

    $make = function (string $name, float $cost, float $price, int $qty) use ($ctx) {
        $product = App\Models\Product::create([
            'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
            'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
            'name' => $name, 'price' => $price, 'cost_price' => $cost,
            'stock_quantity' => $qty, 'reserved_stock' => 0, 'status' => 'published',
        ]);

        App\Models\ProductStoreStock::updateOrCreate(
            ['product_id' => $product->id, 'store_id' => $ctx['store']->id],
            ['quantity' => $qty, 'reserved' => 0],
        );

        return $product;
    };

    $phone = $make('Galaxy', 400000, 650000, 10);
    $cable = $make('Type-C cable', 2000, 3500, 40);
    $case  = $make('Phone case', 1000, 2500, 25);

    $count = app(RecordPhysicalCountAction::class)->execute(
        countedBy: $ctx['owner'], store: $ctx['store'],
        periodStart: now()->subWeek(), periodEnd: now(),
        counts: [$phone->id => 7, $cable->id => 40, $case->id => 25],
    );

    $rows = $count->discrepancies();

    // Only the product that disagreed is listed — a count sheet of everything
    // that was fine tells nobody which shelf to go and look at.
    expect($rows)->toHaveCount(1)
        ->and($rows->first()['product'])->toBe('Galaxy')
        ->and($rows->first()['missing'])->toBe(3)
        ->and($rows->first()['at_selling'])->toBe(1950000.0)
        ->and($rows->first()['at_cost'])->toBe(1200000.0);

    expect($count->variance()['missing_at_selling'])->toBe(1950000.0);
});

test('finding extra of one product does not cancel out another that is missing', function () {
    $ctx = statementContext();

    $short = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Missing item', 'price' => 10000, 'cost_price' => 6000,
        'stock_quantity' => 10, 'reserved_stock' => 0, 'status' => 'published',
    ]);
    $over = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Extra item', 'price' => 10000, 'cost_price' => 6000,
        'stock_quantity' => 10, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    foreach ([$short, $over] as $p) {
        App\Models\ProductStoreStock::updateOrCreate(
            ['product_id' => $p->id, 'store_id' => $ctx['store']->id],
            ['quantity' => 10, 'reserved' => 0],
        );
    }

    $count = app(RecordPhysicalCountAction::class)->execute(
        countedBy: $ctx['owner'], store: $ctx['store'],
        periodStart: now()->subWeek(), periodEnd: now(),
        counts: [$short->id => 8, $over->id => 12],
    );

    $v = $count->variance();

    // Net units are zero, but two units really are missing and that is the
    // figure that has to sit beside the cash. Netting them off would hide it.
    expect($v['units'])->toBe(0)
        ->and($v['missing_at_selling'])->toBe(20000.0)
        ->and($v['lines_short'])->toBe(1)
        ->and($v['lines_over'])->toBe(1);
});

test('a recorded count cannot be edited afterwards', function () {
    $ctx = statementContext();

    $product = App\Models\Product::create([
        'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
        'category_id' => App\Models\Category::create(['name' => 'C' . uniqid()])->id,
        'name' => 'Frozen Widget', 'price' => 1000, 'cost_price' => 500,
        'stock_quantity' => 5, 'reserved_stock' => 0, 'status' => 'published',
    ]);

    $count = app(RecordPhysicalCountAction::class)->execute(
        countedBy: $ctx['owner'], store: $ctx['store'],
        periodStart: now()->subWeek(), periodEnd: now(),
        counts: [$product->id => 5],
    );

    expect(fn () => $count->update(['note' => 'Actually it was fine']))
        ->toThrow(LogicException::class);

    expect(fn () => $count->lines->first()->update(['counted_quantity' => 99]))
        ->toThrow(LogicException::class);
});
