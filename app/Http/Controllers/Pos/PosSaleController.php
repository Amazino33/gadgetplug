<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PosSaleController extends Controller
{
    public function store(Request $request, AdjustStockAction $adjustStock): JsonResponse
    {
        $request->validate([
            'vendor_id'                  => 'required|integer',
            'pos_session_id'             => 'nullable|integer',
            'customer_id'                => 'nullable|integer',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'required|integer',
            'items.*.product_name'       => 'required|string',
            'items.*.product_sku'        => 'nullable|string',
            'items.*.unit_price'         => 'required|numeric|min:0',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.discount_amount'    => 'nullable|numeric|min:0',
            'discount_amount'            => 'nullable|numeric|min:0',
            'discount_type'              => 'nullable|in:percentage,fixed',
            'discount_scope'             => 'nullable|in:item,cart',
            'discount_approved_by'       => 'nullable|integer',
            'vat_rate'                   => 'nullable|numeric|min:0|max:100',
            'payment_method'             => 'required|in:cash,card,bank_transfer',
            'amount_tendered'            => 'nullable|numeric|min:0',
            'bank_transfer_reference'    => 'nullable|string|max:50',
        ]);

        $sale = DB::transaction(function () use ($request, $adjustStock) {
            $subtotal = collect($request->items)->sum(function ($item) {
                $lineTotal = $item['unit_price'] * $item['quantity'];
                return $lineTotal - ($item['discount_amount'] ?? 0);
            });

            $cartDiscount = (float) ($request->discount_amount ?? 0);
            $vatRate      = (float) ($request->vat_rate ?? 7.5);
            $vatAmount    = round(($subtotal - $cartDiscount) * ($vatRate / 100), 2);
            $total        = $subtotal - $cartDiscount + $vatAmount;
            $tendered     = (float) ($request->amount_tendered ?? $total);
            $change       = max(0, $tendered - $total);

            $sale = PosSale::create([
                'reference'               => 'POS-' . strtoupper(Str::random(8)),
                'vendor_id'               => $request->vendor_id,
                'pos_session_id'          => $request->pos_session_id,
                'cashier_id'              => $request->user()->id,
                'customer_id'             => $request->customer_id,
                'subtotal'                => $subtotal,
                'discount_amount'         => $cartDiscount,
                'discount_type'           => $request->discount_type,
                'discount_scope'          => $request->discount_scope,
                'discount_approved_by'    => $request->discount_approved_by,
                'vat_amount'              => $vatAmount,
                'total'                   => $total,
                'payment_method'          => $request->payment_method,
                'amount_tendered'         => $tendered,
                'change_given'            => $change,
                'bank_transfer_reference' => $request->bank_transfer_reference,
                'status'                  => 'completed',
                'synced'                  => true,
                'synced_at'               => now(),
                'completed_at'            => now(),
            ]);

            foreach ($request->items as $item) {
                $lineDiscount = (float) ($item['discount_amount'] ?? 0);
                $lineTotal    = ($item['unit_price'] * $item['quantity']) - $lineDiscount;

                PosSaleItem::create([
                    'pos_sale_id'  => $sale->id,
                    'product_id'   => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_sku'  => $item['product_sku'] ?? null,
                    'unit_price'   => $item['unit_price'],
                    'quantity'     => $item['quantity'],
                    'discount_amount' => $lineDiscount,
                    'total'        => $lineTotal,
                ]);

                // Deduct physical stock immediately (POS = item leaves the shelf now)
                $adjustStock->execute(
                    productId: $item['product_id'],
                    quantityChanged: -$item['quantity'],
                    transactionType: 'pos_sale',
                    userId: $request->user()->id,
                    reference: $sale->reference,
                    description: "POS sale — {$item['product_name']} x{$item['quantity']}",
                );
            }

            // Update customer spend stats
            if ($sale->customer_id) {
                PosCustomer::where('id', $sale->customer_id)->increment('total_spent', $total);
                PosCustomer::where('id', $sale->customer_id)->increment('total_transactions');
            }

            return $sale;
        });

        return response()->json($sale->load('items'), 201);
    }

    public function void(Request $request, PosSale $sale, AdjustStockAction $adjustStock): JsonResponse
    {
        if ($sale->status !== 'completed') {
            return response()->json(['message' => 'Only completed sales can be voided.'], 422);
        }

        DB::transaction(function () use ($sale, $adjustStock, $request) {
            foreach ($sale->items as $item) {
                $adjustStock->execute(
                    productId: $item->product_id,
                    quantityChanged: $item->quantity,
                    transactionType: 'pos_void',
                    userId: $request->user()->id,
                    reference: $sale->reference,
                    description: "Void POS sale — {$item->product_name}",
                );
            }

            $sale->update(['status' => 'voided']);

            if ($sale->customer_id) {
                PosCustomer::where('id', $sale->customer_id)->decrement('total_spent', $sale->total);
                PosCustomer::where('id', $sale->customer_id)->decrement('total_transactions');
            }
        });

        return response()->json(['message' => 'Sale voided.']);
    }

    public function processReturn(Request $request, PosSale $sale, AdjustStockAction $adjustStock): JsonResponse
    {
        $request->validate([
            'items'         => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1',
            'refund_method' => 'required|in:cash,card,bank_transfer,store_credit',
            'reason'        => 'nullable|string|max:255',
        ]);

        $return = DB::transaction(function () use ($request, $sale, $adjustStock) {
            $returnItems   = [];
            $refundAmount  = 0;

            foreach ($request->items as $item) {
                $saleItem = $sale->items->firstWhere('product_id', $item['product_id']);
                if (! $saleItem) continue;

                $itemTotal     = $saleItem->unit_price * $item['quantity'];
                $refundAmount += $itemTotal;

                $returnItems[] = [
                    'product_id'   => $item['product_id'],
                    'product_name' => $saleItem->product_name,
                    'quantity'     => $item['quantity'],
                    'unit_price'   => (float) $saleItem->unit_price,
                    'total'        => $itemTotal,
                ];

                $adjustStock->execute(
                    productId: $item['product_id'],
                    quantityChanged: $item['quantity'],
                    transactionType: 'pos_return',
                    userId: $request->user()->id,
                    reference: $sale->reference,
                    description: "Return — {$saleItem->product_name} x{$item['quantity']}",
                );
            }

            $return = PosReturn::create([
                'reference'        => 'RET-' . strtoupper(Str::random(8)),
                'vendor_id'        => $sale->vendor_id,
                'original_sale_id' => $sale->id,
                'cashier_id'       => $request->user()->id,
                'customer_id'      => $sale->customer_id,
                'return_items'     => $returnItems,
                'refund_amount'    => $refundAmount,
                'refund_method'    => $request->refund_method,
                'reason'           => $request->reason,
            ]);

            if ($sale->status !== 'refunded') {
                $sale->update(['status' => 'refunded']);
            }

            return $return;
        });

        return response()->json($return, 201);
    }

    public function findByReference(Request $request, string $reference): JsonResponse
    {
        $sale = PosSale::with('items')
            ->where('reference', $reference)
            ->where('vendor_id', $request->query('vendor_id'))
            ->firstOrFail();

        return response()->json($sale);
    }

    // Manager approves a discount by verifying their PIN
    public function approveDiscount(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'       => 'required|integer',
            'manager_pin'     => 'required|string',
            'discount_amount' => 'required|numeric|min:0',
            'discount_type'   => 'required|in:percentage,fixed',
        ]);

        $manager = User::whereNotNull('pos_pin')
            ->where(fn ($q) => $q
                ->whereHas('ownedVendors', fn ($q) => $q->where('vendors.id', $request->vendor_id))
                ->orWhereHas('memberVendors', fn ($q) => $q
                    ->where('vendors.id', $request->vendor_id)
                    ->wherePivotIn('role', ['owner', 'inventory_manager'])
                )
            )
            ->get()
            ->first(fn ($u) => Hash::check($request->manager_pin, $u->pos_pin));

        if (! $manager) {
            return response()->json(['message' => 'Invalid manager PIN.'], 401);
        }

        return response()->json([
            'approved'    => true,
            'approved_by' => $manager->id,
            'approver'    => $manager->name,
        ]);
    }
}
