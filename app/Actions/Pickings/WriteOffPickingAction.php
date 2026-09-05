<?php

declare(strict_types=1);

namespace App\Actions\Pickings;

use App\Models\PickingItem;
use App\Models\PickingLedgerEntry;
use App\Services\Pickings\PickingLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The owner's decision to stop chasing goods that will not come back.
 *
 * No stock moves. The units left the shelf when they were picked and were never
 * returned, so there is nothing to take off it — what is being given up is the
 * money, and that is what this records.
 *
 * Valued at the price the picker would have been asked to pay today, which is
 * the sum actually being forgone. What the units originally cost stays on the
 * picking line for anyone measuring the loss the other way.
 */
class WriteOffPickingAction
{
    public function execute(
        PickingItem $item,
        int $quantity,
        ?int $userId = null,
        ?string $note = null,
    ): PickingLedgerEntry {
        if ($quantity < 1) {
            throw new RuntimeException('A write-off has to cover at least one unit.');
        }

        return DB::transaction(function () use ($item, $quantity, $userId, $note) {
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
            $price = (float) $item->product()->value('price');

            return PickingLedgerEntry::create([
                'vendor_id'       => $picking->vendor_id,
                'picking_item_id' => $item->id,
                'direction'       => PickingLedgerEntry::DIRECTION_WRITEOFF,
                'quantity'        => $quantity,
                'amount'          => round($price * $quantity, 2),
                'unit_price'      => $price,
                'user_id'         => $userId,
                'note'            => $note,
            ]);
        });
    }
}
