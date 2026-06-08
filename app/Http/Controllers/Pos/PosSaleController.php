<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Inventory\AdjustStockAction;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
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
            'payment_method'             => 'required|in:cash,card,bank_transfer,split',
            'amount_tendered'            => 'nullable|numeric|min:0',
            'bank_transfer_reference'    => 'nullable|string|max:50',
            'payments'                   => 'required_if:payment_method,split|array|min:2',
            'payments.*.method'          => 'required_if:payment_method,split|in:cash,card,bank_transfer',
            'payments.*.amount'          => 'required_if:payment_method,split|numeric|min:0.01',
            'payments.*.reference'       => 'nullable|string|max:50',
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
            $isSplit      = $request->payment_method === 'split';

            // For split: cash tendered = sum of cash portions; change = cash tendered - cash owed
            $cashTendered = $isSplit
                ? collect($request->payments)->where('method', 'cash')->sum('amount')
                : (float) ($request->amount_tendered ?? $total);
            $change = max(0, $cashTendered - ($isSplit
                ? collect($request->payments)->where('method', 'cash')->sum('amount') - max(0, collect($request->payments)->sum('amount') - $total)
                : $total));

            // Simpler change calculation: total tendered minus total due
            $totalTendered = $isSplit
                ? collect($request->payments)->sum('amount')
                : $cashTendered;
            $change = max(0, $totalTendered - $total);

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
                'amount_tendered'         => $isSplit ? $totalTendered : $cashTendered,
                'change_given'            => $change,
                'bank_transfer_reference' => $isSplit ? null : $request->bank_transfer_reference,
                'status'                  => 'completed',
                'synced'                  => true,
                'synced_at'               => now(),
                'completed_at'            => now(),
            ]);

            // Write split payment rows
            if ($isSplit) {
                foreach ($request->payments as $p) {
                    PosSalePayment::create([
                        'pos_sale_id' => $sale->id,
                        'method'      => $p['method'],
                        'amount'      => $p['amount'],
                        'reference'   => $p['reference'] ?? null,
                    ]);
                }
            }

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

        return response()->json($sale->load(['items', 'payments']), 201);
    }

    public function void(Request $request, PosSale $sale, AdjustStockAction $adjustStock): JsonResponse
    {
        $user   = $request->user();
        $vendor = \App\Models\Vendor::find($sale->vendor_id);

        if (! $vendor?->isOwner($user) && ! $user->hasVendorRole($sale->vendor_id, ['store_admin', 'order_manager', 'inventory_manager'])) {
            return response()->json(['message' => 'Insufficient permissions to void a sale.'], 403);
        }

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

            activity()->causedBy($request->user())
                ->performedOn($sale)
                ->tap(fn ($a) => $a->vendor_id = $sale->vendor_id)
                ->log("Voided sale {$sale->reference}");

            if ($sale->customer_id) {
                PosCustomer::where('id', $sale->customer_id)->decrement('total_spent', $sale->total);
                PosCustomer::where('id', $sale->customer_id)->decrement('total_transactions');
            }
        });

        return response()->json(['message' => 'Sale voided.']);
    }

    public function processReturn(Request $request, PosSale $sale, AdjustStockAction $adjustStock): JsonResponse
    {
        $user   = $request->user();
        $vendor = \App\Models\Vendor::find($sale->vendor_id);

        if (! $vendor?->isOwner($user) && ! $user->hasVendorRole($sale->vendor_id, ['store_admin', 'order_manager', 'inventory_manager'])) {
            return response()->json(['message' => 'Insufficient permissions to process a return.'], 403);
        }

        if ($sale->status === 'voided') {
            return response()->json(['message' => 'Voided sales cannot be returned.'], 422);
        }

        $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1',
            'refund_method'      => 'required|in:cash,card,bank_transfer,store_credit',
            'reason'             => 'nullable|string|max:255',
        ]);

        $sale->loadMissing('items');

        // Sum quantities already returned for this sale, keyed by product_id
        $alreadyReturned = PosReturn::where('original_sale_id', $sale->id)
            ->get()
            ->flatMap(fn ($r) => collect($r->return_items))
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('quantity'));

        // Validate every requested item before touching the DB
        foreach ($request->items as $item) {
            $saleItem = $sale->items->firstWhere('product_id', $item['product_id']);

            if (! $saleItem) {
                return response()->json([
                    'message' => "Product ID {$item['product_id']} was not part of the original sale.",
                ], 422);
            }

            $maxReturnable = $saleItem->quantity - ($alreadyReturned[$item['product_id']] ?? 0);

            if ($item['quantity'] > $maxReturnable) {
                return response()->json([
                    'message' => "Cannot return {$item['quantity']} of \"{$saleItem->product_name}\" — only {$maxReturnable} returnable.",
                ], 422);
            }
        }

        $return = DB::transaction(function () use ($request, $sale, $adjustStock, $alreadyReturned) {
            $returnItems  = [];
            $refundAmount = 0;

            foreach ($request->items as $item) {
                $saleItem      = $sale->items->firstWhere('product_id', $item['product_id']);
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
                    productId:       $item['product_id'],
                    quantityChanged: $item['quantity'],
                    transactionType: 'pos_return',
                    userId:          $request->user()->id,
                    reference:       $sale->reference,
                    description:     "Return — {$saleItem->product_name} x{$item['quantity']}",
                );
            }

            $posReturn = PosReturn::create([
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

            // Check if ALL items from the original sale have now been fully returned
            $totalReturned = $alreadyReturned->merge(
                collect($returnItems)->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'))
            );

            $fullyReturned = $sale->items->every(
                fn ($i) => ($totalReturned[$i->product_id] ?? 0) >= $i->quantity
            );

            $sale->update(['status' => $fullyReturned ? 'refunded' : 'partial_refund']);

            activity()->causedBy($request->user())
                ->performedOn($sale)
                ->withProperties(['refund_amount' => $refundAmount, 'reference' => $posReturn->reference])
                ->tap(fn ($a) => $a->vendor_id = $sale->vendor_id)
                ->log("Processed return {$posReturn->reference} for sale {$sale->reference}");

            return $posReturn;
        });

        return response()->json($return, 201);
    }

    public function findByReference(Request $request, string $reference): JsonResponse
    {
        $sale = PosSale::with('items')
            ->where('reference', $reference)
            ->where('vendor_id', $request->query('vendor_id'))
            ->firstOrFail();

        if ($sale->status === 'voided') {
            return response()->json(['message' => 'This sale has been voided and cannot be returned.'], 422);
        }

        if ($sale->status === 'refunded') {
            return response()->json(['message' => 'All items from this sale have already been returned.'], 422);
        }

        // Attach already-returned quantity to each item so the frontend can cap inputs
        $alreadyReturned = PosReturn::where('original_sale_id', $sale->id)
            ->get()
            ->flatMap(fn ($r) => collect($r->return_items))
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum('quantity'));

        $sale->items->each(function ($item) use ($alreadyReturned) {
            $returned          = $alreadyReturned[$item->product_id] ?? 0;
            $item->returnable  = $item->quantity - $returned;
            $item->returned    = $returned;
        });

        // Filter out items that have nothing left to return
        $sale->setRelation('items', $sale->items->filter(fn ($i) => $i->returnable > 0)->values());

        if ($sale->items->isEmpty()) {
            return response()->json(['message' => 'All items from this sale have already been returned.'], 422);
        }

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
