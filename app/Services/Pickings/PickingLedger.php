<?php

declare(strict_types=1);

namespace App\Services\Pickings;

use App\Models\PickingItem;
use App\Models\PickingLedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What pickers are still holding, and what they still owe for it.
 *
 * Every figure here is derived from picking_ledger_entries. Nothing is stored,
 * so nothing can drift — the same discipline CustomerDebtService follows for
 * money owed.
 *
 * "Held" means units that have not been paid for, brought back, or written off.
 * Those three are the only ways a unit leaves a picker's hands, which is why one
 * sum over the ledger answers it.
 */
class PickingLedger
{
    /** Units of this line the picker is still holding. */
    public static function heldQuantity(PickingItem $item): int
    {
        $accounted = (int) PickingLedgerEntry::where('picking_item_id', $item->id)->sum('quantity');

        return max(0, $item->quantity - $accounted);
    }

    /**
     * Money paid against this line that has not yet completed a unit.
     *
     * A picker paying N15,000 on a N10,000 phone settles one and leaves N5,000
     * sitting here. It is not a balance — it is the difference between what has
     * been paid and what those payments actually covered, so it is recomputed
     * from the rows every time rather than carried anywhere.
     */
    public static function creditOnItem(PickingItem $item): float
    {
        $row = PickingLedgerEntry::query()
            ->where('picking_item_id', $item->id)
            ->where('direction', PickingLedgerEntry::DIRECTION_PAYMENT)
            ->selectRaw('COALESCE(SUM(amount), 0) as paid')
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as covered')
            ->first();

        return round((float) $row->paid - (float) $row->covered, 2);
    }

    /**
     * Units of a product out with pickers — the "on trust" figure.
     *
     * Two queries rather than one clever join: what left, minus what has since
     * been accounted for. Easier to read, and each half is independently
     * checkable against the tables.
     */
    public static function heldQuantityForProduct(int $productId, ?int $storeId = null): int
    {
        $taken = (int) PickingItem::query()
            ->join('pickings', 'pickings.id', '=', 'picking_items.picking_id')
            ->where('picking_items.product_id', $productId)
            ->when($storeId, fn ($q) => $q->where('pickings.store_id', $storeId))
            ->sum('picking_items.quantity');

        $accounted = (int) PickingLedgerEntry::query()
            ->join('picking_items', 'picking_items.id', '=', 'picking_ledger_entries.picking_item_id')
            ->join('pickings', 'pickings.id', '=', 'picking_items.picking_id')
            ->where('picking_items.product_id', $productId)
            ->when($storeId, fn ($q) => $q->where('pickings.store_id', $storeId))
            ->sum('picking_ledger_entries.quantity');

        return max(0, $taken - $accounted);
    }

    /**
     * Every picker still holding something, with what it is worth today.
     *
     * Valued at the product's current price, because that is what they will be
     * asked to pay — so this figure moves when prices move, by design.
     *
     * @return Collection<int, array{picker_id: int, picker_name: string, units: int, value: float}>
     */
    public static function outstandingByPicker(int $vendorId, ?int $storeId = null): Collection
    {
        return self::heldLines($vendorId, $storeId)
            ->groupBy('pickers.id', 'pickers.name')
            ->selectRaw('pickers.id as picker_id, pickers.name as picker_name')
            ->selectRaw('SUM('.self::HELD_SQL.') as units')
            ->selectRaw('SUM('.self::HELD_SQL.' * products.price) as value')
            ->havingRaw('SUM('.self::HELD_SQL.') > 0')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => [
                'picker_id'   => (int) $row->picker_id,
                'picker_name' => $row->picker_name,
                'units'       => (int) $row->units,
                'value'       => (float) $row->value,
            ]);
    }

    /**
     * Who is holding a given product, for the drill-down behind the "on trust"
     * figure.
     *
     * @return Collection<int, array{picker_id: int, picker_name: string, units: int, taken_at: string, reference: ?string}>
     */
    public static function holdersOfProduct(int $productId, ?int $storeId = null): Collection
    {
        return self::heldLines(null, $storeId)
            ->where('picking_items.product_id', $productId)
            ->groupBy('pickers.id', 'pickers.name', 'pickings.taken_at', 'pickings.reference')
            ->selectRaw('pickers.id as picker_id, pickers.name as picker_name')
            ->selectRaw('pickings.taken_at, pickings.reference')
            ->selectRaw('SUM('.self::HELD_SQL.') as units')
            ->havingRaw('SUM('.self::HELD_SQL.') > 0')
            ->orderBy('pickings.taken_at')
            ->get()
            ->map(fn ($row) => [
                'picker_id'   => (int) $row->picker_id,
                'picker_name' => $row->picker_name,
                'units'       => (int) $row->units,
                'taken_at'    => $row->taken_at,
                'reference'   => $row->reference,
            ]);
    }

    /** Units still held on a line, in SQL. */
    private const HELD_SQL = '(picking_items.quantity - COALESCE(accounted.accounted_units, 0))';

    /**
     * Picking lines joined to everything needed to describe them, with what has
     * already been accounted for attached as one grouped subquery rather than a
     * correlated lookup per row.
     */
    private static function heldLines(?int $vendorId, ?int $storeId)
    {
        return PickingItem::query()
            ->join('pickings', 'pickings.id', '=', 'picking_items.picking_id')
            ->join('pickers', 'pickers.id', '=', 'pickings.picker_id')
            ->join('products', 'products.id', '=', 'picking_items.product_id')
            ->leftJoinSub(
                PickingLedgerEntry::query()
                    ->selectRaw('picking_item_id, SUM(quantity) as accounted_units')
                    ->groupBy('picking_item_id'),
                'accounted',
                'accounted.picking_item_id',
                '=',
                'picking_items.id',
            )
            ->when($vendorId, fn ($q) => $q->where('pickings.vendor_id', $vendorId))
            ->when($storeId, fn ($q) => $q->where('pickings.store_id', $storeId));
    }
}
