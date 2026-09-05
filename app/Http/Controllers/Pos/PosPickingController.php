<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pickings\RecordPickingPaymentAction;
use App\Actions\Pickings\ReleaseToPickerAction;
use App\Http\Controllers\Controller;
use App\Models\Picker;
use App\Models\PickingItem;
use App\Services\Inventory\TillStore;
use App\Services\Pickings\PickingLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Taking money from the traders holding the vendor's goods, at the till.
 *
 * The till shows everything still out at the branch it is standing in, whoever
 * released it: the trader comes back on the day he has sold, and whichever
 * cashier is on duty has to be able to deal with him.
 *
 * Allocation is never done here. The till sends "this picker paid this much
 * against these lines" and the server decides what that settles, against live
 * prices and whatever is still outstanding — which is what makes an offline
 * payment safe to queue.
 */
class PosPickingController extends Controller
{
    /** Everything still out at this till's branch, grouped by who is holding it. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendorId = (int) $request->vendor_id;
        $storeId = TillStore::resolve($request->user(), $vendorId);

        $lines = PickingItem::query()
            ->join('pickings', 'pickings.id', '=', 'picking_items.picking_id')
            ->join('pickers', 'pickers.id', '=', 'pickings.picker_id')
            ->join('products', 'products.id', '=', 'picking_items.product_id')
            ->leftJoinSub(
                DB::table('picking_ledger_entries')
                    ->selectRaw('picking_item_id, SUM(quantity) as accounted_units, SUM(amount) as paid, SUM(quantity * unit_price) as covered')
                    ->groupBy('picking_item_id'),
                'led',
                'led.picking_item_id',
                '=',
                'picking_items.id',
            )
            ->where('pickings.vendor_id', $vendorId)
            ->where('pickings.store_id', $storeId)
            // A plain WHERE, not HAVING: the aggregation lives in the joined
            // subquery, so the outer query never groups — and SQLite rejects a
            // HAVING clause on a query that does not.
            ->whereRaw('(picking_items.quantity - COALESCE(led.accounted_units, 0)) > 0')
            ->orderBy('pickers.name')
            ->orderBy('pickings.taken_at')
            ->get([
                'picking_items.id',
                'pickers.id as picker_id',
                'pickers.name as picker_name',
                'pickers.phone as picker_phone',
                'products.name as product_name',
                'pickings.reference as picking_reference',
                'pickings.taken_at',
                DB::raw('products.price as unit_price'),
                DB::raw('(picking_items.quantity - COALESCE(led.accounted_units, 0)) as held'),
                DB::raw('(COALESCE(led.paid, 0) - COALESCE(led.covered, 0)) as credit'),
            ]);

        $pickers = $lines->groupBy('picker_id')->map(fn ($rows) => [
            'id'    => (int) $rows->first()->picker_id,
            'name'  => $rows->first()->picker_name,
            'phone' => $rows->first()->picker_phone,
            'lines' => $rows->map(fn ($row) => [
                'id'           => (int) $row->id,
                'product_name' => $row->product_name,
                'reference'    => $row->picking_reference,
                'taken_at'     => $row->taken_at,
                'held'         => (int) $row->held,
                'unit_price'   => (float) $row->unit_price,
                // Already paid toward the next unit, so the till can show what
                // is really left to pay rather than the full price again.
                'credit'       => round((float) $row->credit, 2),
                'outstanding'  => round((int) $row->held * (float) $row->unit_price - (float) $row->credit, 2),
            ])->values(),
        ])->values();

        return response()->json(['store_id' => $storeId, 'pickers' => $pickers]);
    }

    /**
     * Hand goods to a picker straight from the till.
     *
     * The cashier builds the trip in the cart the same way they build a sale —
     * same scanner, same search — and releases it here instead of taking money
     * for it. Requiring them to leave the counter for the panel to do the one
     * thing they are standing there to do was friction worth removing.
     *
     * Online only, deliberately. Whether a branch actually holds the goods is
     * decided by the server, and a release queued offline could be refused
     * after the trader has already walked out with them — which is a worse
     * position than telling the cashier to wait for a connection.
     */
    public function release(Request $request, ReleaseToPickerAction $release): JsonResponse
    {
        $request->validate([
            'vendor_id'         => 'required|integer',
            'picker_id'         => 'required|integer',
            'items'             => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'  => 'required|integer|min:1',
            'notes'             => 'nullable|string|max:500',
        ]);

        $vendorId = (int) $request->vendor_id;
        $picker = Picker::where('vendor_id', $vendorId)->find($request->picker_id);

        if (! $picker) {
            return response()->json(['message' => 'That picker is not one of yours.'], 404);
        }

        try {
            $picking = $release->execute(
                picker: $picker,
                store: TillStore::resolve($request->user(), $vendorId),
                lines: array_map(fn ($row) => [
                    'product_id' => (int) $row['product_id'],
                    'quantity'   => (int) $row['quantity'],
                ], $request->items),
                userId: $request->user()->id,
                notes: $request->notes,
            );
        } catch (Throwable $e) {
            // Nothing was released: the trip is one transaction, so a line the
            // branch cannot cover takes the whole thing with it.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reference' => $picking->reference,
            'picker'    => $picker->name,
            'lines'     => $picking->items->count(),
        ]);
    }

    /**
     * Take a payment. Also the endpoint the offline queue replays, which is why
     * a repeated reference is answered as a duplicate rather than an error —
     * the till needs to stop retrying, not to be told something went wrong.
     */
    public function pay(Request $request, RecordPickingPaymentAction $record): JsonResponse
    {
        $request->validate([
            'vendor_id'      => 'required|integer',
            'picker_id'      => 'required|integer',
            'amount'         => 'required|numeric|min:0.01',
            'item_ids'       => 'required|array|min:1',
            'item_ids.*'     => 'integer',
            'payment_method' => 'nullable|in:cash,card,bank_transfer',
            'reference'      => 'nullable|string|max:64',
        ]);

        $vendorId = (int) $request->vendor_id;
        $picker = Picker::where('vendor_id', $vendorId)->find($request->picker_id);

        if (! $picker) {
            return response()->json(['message' => 'That picker is not one of yours.'], 404);
        }

        try {
            $result = $record->execute(
                picker: $picker,
                store: TillStore::resolve($request->user(), $vendorId),
                amount: (float) $request->amount,
                itemIds: array_map('intval', $request->item_ids),
                userId: $request->user()->id,
                paymentMethod: $request->payment_method ?? 'cash',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'duplicate'     => $result['duplicate'],
            'allocated'     => $result['allocated'],
            // Hand this back: it completed no unit, so it is still the picker's.
            'change'        => $result['unallocated'],
            'settled_units' => $result['settled_units'],
            'sale_id'       => $result['sale']?->id,
            'reference'     => $result['sale']?->reference,
        ]);
    }
}
