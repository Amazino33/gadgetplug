<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Procurement;
use App\Services\Inventory\TillStore;
use App\Services\Procurement\ProcurementReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;
use Throwable;

class PosProcurementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendorId = (int) $request->vendor_id;
        $storeId = TillStore::resolve($request->user(), $vendorId);

        $procurements = Procurement::with(['items.product', 'creator', 'supplier'])
            ->where('vendor_id', $vendorId)
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                  ->orWhereNull('store_id');
            })
            // Anything still open that this person can do something about:
            // deliveries nobody has picked up, and ones handed back to them
            // mid-disagreement. A batch sitting with the other party is not
            // theirs to act on, and listing it would only offer a button that
            // refuses.
            ->where(function ($q) use ($request) {
                $q->where('status', Procurement::STATUS_PENDING)
                  ->orWhere(fn ($r) => $r
                      ->where('status', Procurement::STATUS_CHANGES_REQUESTED)
                      ->where('awaiting_user_id', $request->user()->id));
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($procurement) {
                return [
                    'id' => $procurement->id,
                    'reference' => $procurement->reference,
                    'total_cost' => (float) $procurement->total_cost,
                    'created_at' => $procurement->created_at,
                    'creator_name' => $procurement->creator?->name ?? 'Unknown',
                    'supplier_name' => $procurement->supplier?->name,
                    'items_count' => $procurement->items->sum('quantity'),
                    'status' => $procurement->status,
                    'items' => $procurement->items->map(fn ($item) => [
                        'id' => $item->id,
                        'product_name' => $item->product?->name ?? 'Unknown Product',
                        'quantity' => (int) $item->quantity,
                        'unit_cost' => (float) $item->unit_cost,
                        // Both figures, so the till can show what was recorded
                        // beside what has been verified rather than quietly
                        // presenting one of them as the other.
                        'verified_quantity' => $item->verifiedQuantity(),
                        'verified_unit_cost' => $item->verifiedUnitCost(),
                        'is_corrected' => $item->isCorrected(),
                    ]),
                ];
            });

        return response()->json([
            'store_id' => $storeId,
            'procurements' => $procurements,
        ]);
    }

    public function approve(Request $request, Procurement $procurement, ProcurementReview $review): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = $this->guardBranch($request, $procurement);

        if (! is_int($storeId)) {
            return $storeId;
        }

        try {
            // Through the same service the panel uses, so receiving at the till
            // is held to the segregation of duties instead of being the way
            // round it. Stock is still received here exactly as before — what
            // changes is that a storekeeper can no longer sign for their own
            // delivery from the till any more than from the panel.
            $review->approve($procurement, $request->user(), $storeId);
        } catch (UnauthorizedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Procurement approved successfully.']);
    }

    /**
     * Say a line is not what it claims, from the till.
     *
     * The counting happens where the cartons are, so the correction has to be
     * recordable there too. Sending somebody to a desktop to record that they
     * counted 10 is how a delivery ends up approved at 12.
     */
    public function correct(Request $request, Procurement $procurement, ProcurementReview $review): JsonResponse
    {
        $data = $request->validate([
            'vendor_id'         => 'required|integer',
            'lines'             => 'required|array|min:1',
            'lines.*.quantity'  => 'nullable|integer|min:0',
            'lines.*.unit_cost' => 'nullable|numeric|min:0',
            'note'              => 'nullable|string|max:1000',
        ]);

        $storeId = $this->guardBranch($request, $procurement);

        if (! is_int($storeId)) {
            return $storeId;
        }

        try {
            $updated = $review->correct(
                $procurement,
                $request->user(),
                $data['lines'],
                $data['note'] ?? null,
            );
        } catch (UnauthorizedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'          => 'Sent back for re-check.',
            'status'           => $updated->status,
            'awaiting_user_id' => $updated->awaiting_user_id,
        ]);
    }

    /**
     * The checks that have nothing to do with who you are: is this delivery
     * ours, and is it even coming here. Returns the branch id, or the response
     * to send back instead of one.
     */
    private function guardBranch(Request $request, Procurement $procurement): int|JsonResponse
    {
        $vendorId = (int) $request->vendor_id;
        $storeId  = TillStore::resolve($request->user(), $vendorId);

        if ((int) $procurement->vendor_id !== $vendorId) {
            return response()->json(['message' => 'Procurement not found or does not belong to you.'], 404);
        }

        if ($procurement->store_id !== null && (int) $procurement->store_id !== $storeId) {
            return response()->json(['message' => 'Procurement is not destined for this branch.'], 422);
        }

        return $storeId;
    }
}
