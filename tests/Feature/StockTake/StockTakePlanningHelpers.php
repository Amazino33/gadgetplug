<?php

// Planning fixtures shared by the step 4 tests (see Helpers.php for why
// helpers live in their own file).

use App\Models\AisleProduct;
use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeEntry;
use App\Models\StockTakeTask;
use App\Services\StockTake\StockTakePlanner;

function stMap(array $ctx, $aisle, Product ...$products): void
{
    foreach ($products as $product) {
        $aisle->products()->attach($product->id, [
            'vendor_id' => $ctx['vendor']->id,
            'source'    => AisleProduct::SOURCE_MAPPING,
            'mapped_by' => $ctx['admin']->id,
            'mapped_at' => now(),
        ]);
    }
}

function stPlanner(): StockTakePlanner
{
    return app(StockTakePlanner::class);
}

function stOpen(array $ctx, array $over = []): StockTake
{
    return stPlanner()->open($ctx['admin'], $ctx['store'], array_merge([
        'name' => 'November Full Count', 'start_date' => '2026-11-24', 'end_date' => '2026-11-30',
    ], $over));
}

/** A count task handed in with these products counted in this aisle. */
function stHandIn(array $ctx, StockTake $stockTake, $aisle, array $products, string $countedAt = '2026-11-24 10:00:00'): StockTakeTask
{
    $task = stPlanner()->assignCount($ctx['admin'], $stockTake, [$aisle->id], $ctx['keeperA'], '2026-11-24');

    foreach ($products as $product) {
        StockTakeEntry::create([
            'vendor_id' => $ctx['vendor']->id, 'store_id' => $ctx['store']->id,
            'stock_take_task_id' => $task->id, 'aisle_id' => $aisle->id, 'product_id' => $product->id,
            'counted_by' => $ctx['keeperA']->id, 'counted_qty_base' => 5, 'counted_at' => $countedAt,
        ]);
    }

    $task->update(['status' => StockTakeTask::STATUS_SUBMITTED, 'submitted_at' => now()]);

    return $task;
}
