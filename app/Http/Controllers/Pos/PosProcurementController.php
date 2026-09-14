<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Procurement\ApproveProcurementAction;
use App\Http\Controllers\Controller;
use App\Models\Procurement;
use App\Services\Inventory\TillStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->where('store_id', $storeId)
            ->where('status', 'pending')
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
                    'items' => $procurement->items->map(fn ($item) => [
                        'product_name' => $item->product?->name ?? 'Unknown Product',
                        'quantity' => (int) $item->quantity,
                        'unit_cost' => (float) $item->unit_cost,
                    ]),
                ];
            });

        return response()->json([
            'store_id' => $storeId,
            'procurements' => $procurements,
        ]);
    }

    public function approve(Request $request, Procurement $procurement, ApproveProcurementAction $approveAction): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $vendorId = (int) $request->vendor_id;
        $storeId = TillStore::resolve($request->user(), $vendorId);

        if ((int) $procurement->vendor_id !== $vendorId) {
            return response()->json(['message' => 'Procurement not found or does not belong to you.'], 404);
        }

        if ($procurement->store_id !== null && (int) $procurement->store_id !== $storeId) {
             return response()->json(['message' => 'Procurement is not destined for this branch.'], 422);
        }

        if (!$procurement->isPending()) {
            return response()->json(['message' => 'Only pending procurements can be approved.'], 422);
        }

        try {
            $approveAction->execute($procurement, $storeId);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Procurement approved successfully.']);
    }
}
