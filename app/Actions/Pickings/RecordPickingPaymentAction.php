<?php

declare(strict_types=1);

namespace App\Actions\Pickings;

use App\Actions\Finance\RecognizePosSaleRevenueAction;
use App\Models\Picker;
use App\Models\PickingItem;
use App\Models\PickingLedgerEntry;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Store;
use App\Services\Pickings\PickingLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Money a picker brings back for what they have sold.
 *
 * The cashier ticks the lines being paid for and enters the cash. This fills
 * those lines in the order they were ticked, settling whole units only: a unit
 * is not sold until it is fully paid, because half a phone cannot be. Money
 * that does not complete the next unit stays against that line and waits for
 * the rest — which is what makes a N15,000 payment on a N10,000 phone settle
 * one and leave N5,000 sitting there.
 *
 * The allocation is always done HERE, never on the till. An offline payment
 * carries only "this picker paid this much against these lines", and the server
 * decides what that settles against live prices and whatever is still out. Two
 * cashiers who both took money offline therefore cannot double-settle: whoever
 * syncs second finds less outstanding and the balance simply remains.
 *
 * Settled units become a POS sale so the money reaches the Sales Report, the
 * cashier breakdown and the financial ledger by the same road as every other
 * sale. No stock moves — it left the shelf when the goods were picked, possibly
 * months earlier. That is also why the sale's cost comes off the picking line
 * rather than the product: it is what those exact units actually cost.
 */
class RecordPickingPaymentAction
{
    public function __construct(
        private readonly RecognizePosSaleRevenueAction $recognizeRevenue,
    ) {
    }

    /**
     * @param  array<int, int>  $itemIds  Lines to settle, in the order to fill them.
     * @return array{sale: ?PosSale, allocated: float, unallocated: float, settled_units: int, duplicate: bool}
     */
    public function execute(
        Picker $picker,
        Store|int $store,
        float $amount,
        array $itemIds,
        ?int $userId = null,
        string $paymentMethod = 'cash',
        ?string $reference = null,
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('A payment has to be for something.');
        }

        if ($itemIds === []) {
            throw new RuntimeException('Choose what the money is paying for.');
        }

        // Somebody took this money. The sale a settled unit writes records the
        // cashier and cannot be written without one, so the demand is made here
        // where it can be explained rather than as a constraint violation.
        if (! $userId) {
            throw new RuntimeException('A payment has to be recorded against whoever took it.');
        }

        $storeId = $store instanceof Store ? $store->id : $store;

        return DB::transaction(function () use ($picker, $storeId, $amount, $itemIds, $userId, $paymentMethod, $reference) {
            // Checked inside the transaction rather than by a unique index: two
            // tills syncing the same queued payment would both pass a check made
            // outside it, and both allocate.
            if ($reference && PickingLedgerEntry::where('vendor_id', $picker->vendor_id)
                ->where('reference', $reference)
                ->lockForUpdate()
                ->exists()) {
                return [
                    'sale'          => null,
                    'allocated'     => 0.0,
                    'unallocated'   => 0.0,
                    'settled_units' => 0,
                    'duplicate'     => true,
                ];
            }

            $remaining = round($amount, 2);
            $settled   = [];
            $lines     = [];

            // Read every ticked line first: allocation runs over the set twice,
            // and both passes need the same locked rows and the same prices.
            foreach ($itemIds as $itemId) {
                $item = PickingItem::where('id', $itemId)->lockForUpdate()->first();

                if (! $item || ! $this->belongsToPicker($item, $picker)) {
                    throw new RuntimeException('That line is not this picker\'s.');
                }

                $price = (float) $item->product()->value('price');

                // A product priced at nothing cannot be settled by paying — it
                // would take every unit for no money and silently clear the
                // line. Left outstanding for someone to price properly.
                if ($price <= 0) {
                    continue;
                }

                $lines[] = [
                    'item'   => $item,
                    'price'  => $price,
                    'held'   => PickingLedger::heldQuantity($item),
                    'credit' => PickingLedger::creditOnItem($item),
                ];
            }

            // First pass: settle whole units wherever the money reaches, taking
            // from each line only what completes units on it.
            //
            // Deliberately not "spend everything on the first line, then move
            // on". N15,000 across a N6,000, a N10,000 and a N4,000 settles the
            // 6 and the 4 and leaves 5 against the 10 — spending in strict order
            // would sink 9,000 into the 10,000 and settle the 4,000 not at all,
            // which is a worse answer for the picker and not what was asked for.
            foreach ($lines as $index => $line) {
                if ($remaining <= 0 || $line['held'] < 1) {
                    continue;
                }

                $affordable = (int) floor(($line['credit'] + $remaining) / $line['price']);
                $units = min($line['held'], $affordable);

                if ($units < 1) {
                    continue;
                }

                $assign = round($units * $line['price'] - $line['credit'], 2);

                $this->writePayment($picker, $line['item'], $units, $assign, $line['price'], $userId, $reference);

                $remaining = round($remaining - $assign, 2);
                $lines[$index]['held'] -= $units;
                $lines[$index]['credit'] = 0.0;

                $settled[] = [
                    'item'       => $line['item'],
                    'units'      => $units,
                    'unit_price' => $line['price'],
                ];
            }

            // Second pass: whatever is left could not complete a unit anywhere,
            // so it waits on the first line still outstanding. That is the
            // N5,000 sitting against the phone until the rest of it arrives.
            if ($remaining > 0) {
                foreach ($lines as $line) {
                    if ($line['held'] < 1) {
                        continue;
                    }

                    $this->writePayment($picker, $line['item'], 0, $remaining, $line['price'], $userId, $reference);
                    $remaining = 0.0;

                    break;
                }
            }

            $sale = $settled === []
                ? null
                : $this->recordSale($picker, $storeId, $settled, $userId, $paymentMethod);

            return [
                'sale'          => $sale,
                'allocated'     => round($amount - $remaining, 2),
                // Money the ticked lines had no use for. The cashier hands it
                // back — it is not recorded, because nothing was sold for it.
                'unallocated'   => max(0.0, $remaining),
                'settled_units' => array_sum(array_column($settled, 'units')),
                'duplicate'     => false,
            ];
        });
    }

    /**
     * One row of the ledger: what this money did to this line.
     *
     * quantity 0 is meaningful and not a no-op — it is money banked against a
     * line that it could not yet complete, and creditOnItem() reads exactly
     * this difference between what was paid and what it covered.
     */
    private function writePayment(
        Picker $picker,
        PickingItem $item,
        int $units,
        float $amount,
        float $price,
        int $userId,
        ?string $reference,
    ): void {
        PickingLedgerEntry::create([
            'vendor_id'       => $picker->vendor_id,
            'picking_item_id' => $item->id,
            'direction'       => PickingLedgerEntry::DIRECTION_PAYMENT,
            'reference'       => $reference,
            'quantity'        => $units,
            'amount'          => $amount,
            'unit_price'      => $price,
            'user_id'         => $userId,
        ]);
    }

    private function belongsToPicker(PickingItem $item, Picker $picker): bool
    {
        return (int) $item->picking()->value('picker_id') === (int) $picker->id;
    }

    /**
     * @param  array<int, array{item: PickingItem, units: int, unit_price: float}>  $settled
     */
    private function recordSale(
        Picker $picker,
        int $storeId,
        array $settled,
        ?int $userId,
        string $paymentMethod,
    ): PosSale {
        $total = round(array_reduce(
            $settled,
            fn (float $carry, array $row) => $carry + ($row['units'] * $row['unit_price']),
            0.0,
        ), 2);

        $sale = PosSale::create([
            'reference'       => 'PICK-'.strtoupper(Str::random(8)),
            'vendor_id'       => $picker->vendor_id,
            'store_id'        => $storeId,
            'cashier_id'      => $userId,
            'customer_id'     => null,
            'subtotal'        => $total,
            'discount_amount' => 0,
            'vat_amount'      => 0,
            'total'           => $total,
            'payment_method'  => $paymentMethod,
            // The sale is worth what was settled, not what was handed over:
            // money beyond that completes no unit and is still the picker's.
            'amount_tendered' => $total,
            'change_given'    => 0,
            'status'          => 'completed',
            'synced'          => true,
            'synced_at'       => now(),
            'completed_at'    => now(),
        ]);

        foreach ($settled as $row) {
            /** @var PickingItem $item */
            $item = $row['item'];
            $product = $item->product()->first();

            PosSaleItem::create([
                'pos_sale_id'     => $sale->id,
                'product_id'      => $item->product_id,
                'product_name'    => $product?->name ?? 'Product',
                'product_sku'     => $product?->sku,
                'unit_price'      => $row['unit_price'],
                // What these exact units cost when they left the shelf, kept on
                // the picking line for this moment. The product's cost price
                // today may be nothing like it.
                'unit_cost'       => $item->unit_cost,
                'quantity'        => $row['units'],
                'discount_amount' => 0,
                'total'           => round($row['units'] * $row['unit_price'], 2),
            ]);
        }

        $this->recognizeRevenue->execute($sale);

        return $sale;
    }
}
