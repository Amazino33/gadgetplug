<?php

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Procurement\ApproveProcurementAction;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\ProductStoreStock;
use App\Models\StockCostLayer;
use App\Models\Supplier;
use App\Services\Inventory\StockCostLayers;
use App\Services\Inventory\StockValuation;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Stock used to be valued by multiplying every unit by the product's single
// cost_price, which the last procurement overwrote. Restocking dearer therefore
// revalued cheap stock and invented profit. Units are now valued in the batches
// they arrived in.
//
// layersContext(), receiveBatch() and stockValue() live in tests/Pest.php so
// the cost-of-goods-sold suite can use the same fixtures.

test('two batches at different costs are worth what was actually paid', function () {
    $ctx = layersContext();

    receiveBatch($ctx, 10, 1000.0);
    expect(stockValue($ctx))->toBe(10000.0);

    receiveBatch($ctx, 10, 1500.0);

    // The old calculation reported 20 x 1,500 = 30,000 here.
    expect(stockValue($ctx))->toBe(25000.0);
});

test('selling draws from the oldest batch first', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);
    receiveBatch($ctx, 10, 1500.0);

    // Twelve out: all ten of the cheap batch, then two of the dearer one.
    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id,
        quantityChanged: -12,
        transactionType: 'pos_sale',
        store: $ctx['store']->id,
    );

    // Eight left, all from the 1,500 batch.
    expect(stockValue($ctx))->toBe(12000.0)
        ->and((int) $ctx['product']->fresh()->stock_quantity)->toBe(8);
});

test('what the sold units cost is reported back, oldest batch first', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);
    receiveBatch($ctx, 10, 1500.0);

    $result = StockCostLayers::consume($ctx['product']->id, $ctx['store']->id, 12);

    // 10 x 1,000 + 2 x 1,500
    expect($result['cost'])->toBe(13000.0)
        ->and($result['consumed'])->toBe(12)
        ->and($result['shortfall'])->toBe(0);
});

test('the batches always add up to the stock actually on the shelf', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);
    receiveBatch($ctx, 5, 1500.0);

    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id, quantityChanged: -7,
        transactionType: 'pos_sale', store: $ctx['store']->id,
    );

    $onShelf = (int) ProductStoreStock::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)->value('quantity');

    $inBatches = (int) StockCostLayer::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)->sum('quantity_remaining');

    expect($inBatches)->toBe($onShelf)->toBe(8);
});

test('stock coming back creates a batch at what the product currently costs', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);

    // A return, a void, or an upward audit correction.
    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id, quantityChanged: 3,
        transactionType: 'pos_return', store: $ctx['store']->id,
    );

    // cost_price was set to 1,000 by the procurement above.
    expect(stockValue($ctx))->toBe(13000.0);
});

test('goods received with no cost recorded are counted but never valued', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);

    StockCostLayers::receive(
        productId: $ctx['product']->id, storeId: $ctx['store']->id,
        quantity: 5, unitCost: null,
    );
    ProductStoreStock::where('product_id', $ctx['product']->id)
        ->where('store_id', $ctx['store']->id)->increment('quantity', 5);

    $valuation = StockValuation::forVendor($ctx['vendor']->id);

    expect($valuation['value'])->toBe(10000.0)
        ->and($valuation['uncosted_units'])->toBe(5)
        ->and($valuation['uncosted_product_count'])->toBe(1);
});

test('stock that never passed through a stock action is still valued, not lost', function () {
    // A seeded fixture or a bulk import writes the stock row directly and has
    // no batch behind it. Those units fall back to the product's cost_price,
    // which is exactly what the old calculation did.
    $ctx = debtTenderContext();

    expect((int) $ctx['product']->stock_quantity)->toBe(50)
        ->and(StockCostLayer::where('product_id', $ctx['product']->id)->count())->toBe(0);

    expect(stockValue($ctx))->toBe(50 * 6000.0);
});

test('a batch covers what it can and the rest falls back, never double-counted', function () {
    $ctx = debtTenderContext(); // 50 units, cost 6,000, no layers
    $ctx['supplier'] = Supplier::create(['vendor_id' => $ctx['vendor']->id, 'name' => 'S']);

    // Ten more arrive at 1,000, so 60 units: 10 batched, 50 unbatched.
    receiveBatch($ctx, 10, 1000.0);

    // The procurement also reset cost_price to 1,000, so the 50 unbatched
    // units fall back to that.
    expect(stockValue($ctx))->toBe((10 * 1000.0) + (50 * 1000.0));
});

test('the financial report values stock from the batches', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);
    receiveBatch($ctx, 10, 1500.0);

    $report = app(FinancialReportService::class)
        ->report($ctx['vendor']->id, now()->subDay(), now()->addDay());

    expect($report['balances']['inventory_value'])->toBe(25000.0)
        ->and($report['balances']['inventory_cost_is_partial'])->toBeFalse();
});

test('each branch holds its own batches', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 10, 1000.0);

    $other = layersContext();
    receiveBatch($other, 10, 5000.0);

    expect(stockValue($ctx))->toBe(10000.0)
        ->and(stockValue($other))->toBe(50000.0);
});

test('consuming more than exists takes what there is and reports the shortfall', function () {
    // Dispatch is allowed to drive stock negative, so consumption can be asked
    // for units no batch covers. Taking what exists and saying so beats
    // refusing to let the goods leave.
    $ctx = layersContext();
    receiveBatch($ctx, 5, 1000.0);

    $result = StockCostLayers::consume($ctx['product']->id, $ctx['store']->id, 8);

    expect($result['consumed'])->toBe(5)
        ->and($result['shortfall'])->toBe(3)
        ->and($result['cost'])->toBe(5000.0);
});

test('an empty shelf is worth nothing once the stock row agrees', function () {
    $ctx = layersContext();
    receiveBatch($ctx, 5, 1000.0);

    app(AdjustStockAction::class)->execute(
        productId: $ctx['product']->id, quantityChanged: -5,
        transactionType: 'pos_sale', store: $ctx['store']->id,
    );

    expect(stockValue($ctx))->toBe(0.0);
});

test('units the batches do not cover are valued rather than lost', function () {
    // The reconciler at work: batches exhausted, but the stock row still says
    // units are there. Valuing them at zero would understate the shelf badly
    // and silently, so they fall back to the product's cost_price.
    $ctx = layersContext();
    receiveBatch($ctx, 5, 1000.0);

    StockCostLayers::consume($ctx['product']->id, $ctx['store']->id, 5);

    expect((int) ProductStoreStock::where('product_id', $ctx['product']->id)->value('quantity'))->toBe(5)
        ->and(stockValue($ctx))->toBe(5000.0);
});
