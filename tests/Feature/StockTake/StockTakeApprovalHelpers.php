<?php

// Approval fixtures shared by the step 6 tests (see Helpers.php for why
// helpers live in their own file).

use App\Actions\Inventory\AdjustStockAction;
use App\Models\ProductStoreStock;
use App\Models\ProductUnit;
use App\Models\StockTakeResult;
use App\Models\StockTakeTask;
use App\Models\User;
use App\Services\StockTake\StockTakeApprover;
use App\Services\StockTake\StockTakeCounter;

function stShelf(array $ctx, $product): int|float
{
    return ProductStoreStock::where('product_id', $product->id)->where('store_id', $ctx['store']->id)->value('quantity') + 0;
}

function stSell(array $ctx, $product, int $qty): void
{
    app(AdjustStockAction::class)->execute(productId: $product->id, quantityChanged: -$qty, transactionType: 'pos_sale', store: $ctx['store']->id);
}

/** Count a product in a task through the real counter, then hand the task in. */
function stCountAndHandIn(array $ctx, StockTakeTask $task, User $who, array $counts, array $decisions = []): void
{
    $counter = app(StockTakeCounter::class);

    foreach ($counts as [$aisle, $product, $qty]) {
        $base = ProductUnit::where('product_id', $product->id)->where('is_base', true)->value('id');
        $counter->save($who, $task->fresh(), $aisle, $product, [['unit_id' => $base, 'qty' => $qty]], StockTakeCounter::MODE_NEW);
    }

    $counter->submit($who, $task->fresh(), $decisions);
}

function stApprover(): StockTakeApprover
{
    return app(StockTakeApprover::class);
}

function stResultFor(StockTakeTask $task, $product): StockTakeResult
{
    return StockTakeResult::where('stock_take_task_id', $task->id)->where('product_id', $product->id)->sole();
}

/** A stock take with one aisle holding one product with 50 on the books. */
function stOneProduct(array $over = []): array
{
    $ctx   = stockTakeContext();
    $milk  = stProduct($ctx, 'Peak Milk', $over['books'] ?? 50);
    $milk->update(['cost_price' => 1000]);
    $aisle = stAisle($ctx, 'Aisle 2');
    stMap($ctx, $aisle, $milk);
    $st    = stOpen($ctx, $over['stock_take'] ?? []);
    $task  = stPlanner()->assignCount($ctx['admin'], $st, [$aisle->id], $ctx['keeperA'], null);

    return [$ctx, $milk, $aisle, $st, $task];
}
