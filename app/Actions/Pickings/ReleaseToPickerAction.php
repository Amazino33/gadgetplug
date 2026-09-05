<?php

declare(strict_types=1);

namespace App\Actions\Pickings;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Picker;
use App\Models\Picking;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hand goods to a picker to sell in their own shop.
 *
 * The units leave the shelf now. They are not sold — the vendor still owns them
 * and can ask for them back — but they are genuinely gone from the branch, so
 * the till cannot sell them and a counter will not find them. What they cost is
 * captured here from the cost layers, because these exact units may be paid for
 * months later, by which time the product's cost price will have moved.
 */
class ReleaseToPickerAction
{
    public function __construct(private readonly AdjustStockAction $adjustStock)
    {
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     */
    public function execute(
        Picker $picker,
        Store|int $store,
        array $lines,
        ?int $userId = null,
        ?string $notes = null,
        ?Carbon $takenAt = null,
    ): Picking {
        if ($lines === []) {
            throw new RuntimeException('A picking needs at least one product.');
        }

        $storeId = $store instanceof Store ? $store->id : $store;

        return DB::transaction(function () use ($picker, $storeId, $lines, $userId, $notes, $takenAt) {
            $store = Store::where('vendor_id', $picker->vendor_id)->find($storeId);

            if (! $store) {
                throw new RuntimeException('That branch does not belong to this vendor.');
            }

            $picking = Picking::create([
                'vendor_id'   => $picker->vendor_id,
                'store_id'    => $store->id,
                'picker_id'   => $picker->id,
                'released_by' => $userId,
                'taken_at'    => $takenAt ?? now(),
                'notes'       => $notes,
            ]);

            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity < 1) {
                    throw new RuntimeException('Every line must take at least one unit.');
                }

                $product = Product::where('vendor_id', $picker->vendor_id)
                    ->find($line['product_id']);

                if (! $product) {
                    throw new RuntimeException('That product does not belong to this vendor.');
                }

                // Throws when the branch does not hold enough, which is the
                // guard that stops goods being handed out on paper that are not
                // on the shelf to hand out.
                $ledger = $this->adjustStock->execute(
                    productId: $product->id,
                    quantityChanged: -$quantity,
                    transactionType: 'picking_out',
                    userId: $userId,
                    reference: $picking->reference,
                    description: "Picked by {$picker->name} — {$product->name} x{$quantity}",
                    store: $store->id,
                );

                // What these units actually cost, drawn from the cost layers as
                // they left. Null only when the product has no cost recorded at
                // all, in which case profit on the eventual sale is as unknown
                // as it is anywhere else in the system.
                $costTotal = $ledger->cost_total;

                $picking->items()->create([
                    'product_id' => $product->id,
                    'quantity'   => $quantity,
                    'unit_cost'  => $costTotal !== null ? round((float) $costTotal / $quantity, 2) : null,
                ]);
            }

            return $picking->fresh(['items']);
        });
    }
}
