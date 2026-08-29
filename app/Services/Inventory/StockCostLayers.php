<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\StockCostLayer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

// The only writer of stock_cost_layers.
//
// Every call happens inside the caller's transaction, with the product row
// already locked for update — the same lock the stock actions have always
// taken. That lock is what keeps the layers in step with
// product_store_stocks: two writers moving the same product in different
// branches still serialise on it, so neither can draw down a layer the other
// is mid-way through writing.
//
// The invariant this class exists to hold:
//
//     SUM(layer.quantity_remaining)  ==  MAX(0, product_store_stocks.quantity)
//
// for every (product, store). Valuation trusts it, and reconcile-on-read in
// StockValuation covers the cases it cannot reach — stock seeded by an import
// or a fixture, which never passed through a stock action at all.
class StockCostLayers
{
    /**
     * Units arriving. A receipt of zero or fewer is not a receipt.
     *
     * $unitCost null means "nobody has said what these cost yet" and is kept as
     * null rather than coerced to zero — valuing unknown goods at nothing is a
     * claim, and the report says so out loud instead.
     */
    public static function receive(
        int $productId,
        int $storeId,
        int $quantity,
        ?float $unitCost,
        ?Model $source = null,
        ?Carbon $receivedAt = null,
    ): ?StockCostLayer {
        if ($quantity <= 0) {
            return null;
        }

        return StockCostLayer::create([
            'product_id'         => $productId,
            'store_id'           => $storeId,
            'unit_cost'          => $unitCost,
            'quantity_received'  => $quantity,
            'quantity_remaining' => $quantity,
            'source_type'        => $source?->getMorphClass(),
            'source_id'          => $source?->getKey(),
            'received_at'        => $receivedAt ?? now(),
        ]);
    }

    /**
     * Units leaving, drawn from the oldest layer first.
     *
     * Returns what those units cost — the figure Phase 2 will book as cost of
     * goods sold. Today only the caller's own bookkeeping uses it; valuation
     * cares about what is left behind, not what went out.
     *
     * A shortfall is possible and deliberately tolerated: DispatchStockAction
     * lets physical stock go negative so a dispatch is never blocked by a
     * miscount, and there is no layer to draw from below zero. Consuming what
     * exists and reporting it is the honest outcome — refusing here would stop
     * goods leaving over a bookkeeping gap.
     *
     * @return array{cost: float, consumed: int, shortfall: int, uncosted_units: int}
     */
    public static function consume(int $productId, int $storeId, int $quantity): array
    {
        if ($quantity <= 0) {
            return ['cost' => 0.0, 'consumed' => 0, 'shortfall' => 0, 'uncosted_units' => 0];
        }

        $layers = StockCostLayer::query()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $outstanding = $quantity;
        $cost = 0.0;
        $uncosted = 0;

        foreach ($layers as $layer) {
            if ($outstanding <= 0) {
                break;
            }

            $take = min($outstanding, $layer->quantity_remaining);

            if ($layer->unit_cost === null) {
                $uncosted += $take;
            } else {
                $cost += $take * (float) $layer->unit_cost;
            }

            $layer->quantity_remaining -= $take;
            $layer->save();

            $outstanding -= $take;
        }

        return [
            'cost'           => round($cost, 2),
            'consumed'       => $quantity - $outstanding,
            'shortfall'      => $outstanding,
            'uncosted_units' => $uncosted,
        ];
    }

    /**
     * Applies a signed stock movement to the layers.
     *
     * The stock actions all speak in signed changes, so this saves each of them
     * branching on the sign — and means a new stock action has one call to make
     * rather than two to remember.
     *
     * Returns what the units cost when this was a movement OUT, so the caller
     * can book it as cost of goods sold. Null for a receipt, which has no cost
     * of sale to report.
     *
     * @return array{cost: float, consumed: int, shortfall: int, uncosted_units: int}|null
     */
    public static function applyMovement(
        Product $product,
        int $storeId,
        int $quantityChanged,
        ?float $unitCost = null,
        ?Model $source = null,
    ): ?array {
        if ($quantityChanged > 0) {
            // Units coming back or being corrected upward have no purchase
            // document of their own, so they are costed at what the product is
            // currently understood to cost.
            self::receive(
                productId: $product->id,
                storeId: $storeId,
                quantity: $quantityChanged,
                unitCost: $unitCost ?? ($product->cost_price !== null ? (float) $product->cost_price : null),
                source: $source,
            );

            return null;
        }

        if ($quantityChanged < 0) {
            return self::consume($product->id, $storeId, abs($quantityChanged));
        }

        return null;
    }
}
