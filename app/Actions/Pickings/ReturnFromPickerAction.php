<?php

declare(strict_types=1);

namespace App\Actions\Pickings;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\PickingItem;
use App\Models\PickingLedgerEntry;
use App\Services\Pickings\PickingLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods brought back unsold, or gone to fetch and recovered.
 *
 * They return to the branch they left, not to whichever branch is convenient —
 * that is where the stock left from, and sending them elsewhere would quietly
 * move stock between branches under cover of a return.
 *
 * The units re-enter at the product's current cost price rather than what they
 * cost when they left, because AdjustStockAction costs every inbound movement
 * that way. Every other return in the system behaves identically, so this stays
 * consistent rather than inventing a second rule for one case.
 */
class ReturnFromPickerAction
{
    public function __construct(private readonly AdjustStockAction $adjustStock)
    {
    }

    public function execute(
        PickingItem $item,
        int $quantity,
        ?int $userId = null,
        ?string $note = null,
    ): PickingLedgerEntry {
        if ($quantity < 1) {
            throw new RuntimeException('A return has to bring back at least one unit.');
        }

        return DB::transaction(function () use ($item, $quantity, $userId, $note) {
            // Locked so two people cannot both return the last unit: the held
            // figure is read from the ledger, and without this both would read
            // the same number before either had written.
            $item = PickingItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $held = PickingLedger::heldQuantity($item);

            if ($quantity > $held) {
                throw new RuntimeException(
                    $held === 0
                        ? 'Nothing is still out on that line.'
                        : "Only {$held} of that line is still out.",
                );
            }

            $picking = $item->picking()->firstOrFail();
            $product = $item->product()->firstOrFail();

            $this->adjustStock->execute(
                productId: $item->product_id,
                quantityChanged: $quantity,
                transactionType: 'picking_return',
                userId: $userId,
                reference: $picking->reference,
                description: "Returned by {$picking->picker->name} — {$product->name} x{$quantity}",
                store: $picking->store_id,
            );

            return PickingLedgerEntry::create([
                'vendor_id'       => $picking->vendor_id,
                'picking_item_id' => $item->id,
                'direction'       => PickingLedgerEntry::DIRECTION_RETURN,
                'quantity'        => $quantity,
                'amount'          => 0,
                'user_id'         => $userId,
                'note'            => $note,
            ]);
        });
    }
}
