<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Finance\RecognizePosSaleRevenueAction;
use App\Actions\Pos\ChargeCustomerDebtAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Inventory\TillStore;
use App\Services\Pos\PosPriceFloor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosSaleController extends Controller
{
    /** Whether this payload puts any part of the sale on credit. */
    private function salePayloadHasDebt(Request $request): bool
    {
        if ($request->payment_method === 'debt') {
            return true;
        }

        return $request->payment_method === 'split'
            && collect($request->payments ?? [])->contains(fn ($p) => ($p['method'] ?? null) === 'debt');
    }

    public function store(Request $request, AdjustStockAction $adjustStock, PosPriceFloor $priceFloor, RecognizePosSaleRevenueAction $revenue): JsonResponse
    {
        $request->validate([
            'vendor_id'                  => 'required|integer',
            // The till's own id for this checkout. Not required — an older
            // client may not send one — but when present it makes this endpoint
            // idempotent, which is what stops a lost response becoming a second
            // sale of the same goods.
            'offline_id'                 => 'nullable|string|max:64',
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
            'payment_method'             => 'required|in:cash,card,bank_transfer,split,debt',
            'amount_tendered'            => 'nullable|numeric|min:0',
            'bank_transfer_reference'    => 'nullable|string|max:50',
            // 'nullable' is required here even though 'required_if' is present:
            // every non-split sale sends `payments: null` explicitly (not
            // omitted), and without 'nullable' Laravel still runs 'array'/'min'
            // against that null value — rejecting EVERY plain cash/card/bank
            // transfer sale with "the payments field must be an array."
            'payments'                   => 'nullable|required_if:payment_method,split|array|min:2',
            'payments.*.method'          => 'required_if:payment_method,split|in:cash,card,bank_transfer,debt',
            'payments.*.amount'          => 'required_if:payment_method,split|numeric|min:0.01',
            'payments.*.reference'       => 'nullable|string|max:50',
        ]);

        // Debt has to be owed by somebody. The till blocks this at the payment
        // screen, so reaching here without a customer means the payload was
        // hand-built — and an anonymous debt is worse than a refused sale,
        // because nobody can ever be asked to pay it.
        if ($this->salePayloadHasDebt($request) && ! $request->customer_id) {
            throw ValidationException::withMessages([
                'customer_id' => 'A credit sale must be attached to a customer.',
            ]);
        }

        $vendor = Vendor::findOrFail($request->vendor_id);

        // Derived from the till's offline_id, exactly as PosSyncController does.
        //
        // The two paths used different schemes: sync built the reference from
        // the offline_id so a replay could be recognised, while this endpoint
        // used a random one. A sale that committed here but whose response never
        // reached the till was therefore queued and re-sent, and the sync's
        // duplicate check looked for a reference this path had never written —
        // so it made a second sale, deducting the stock and counting the money
        // twice. Both paths now name a sale the same way.
        $offlineId = $request->input('offline_id');
        $reference = $offlineId
            ? 'POS-' . strtoupper(substr(md5($offlineId), 0, 8))
            : 'POS-' . strtoupper(Str::random(8));

        // A replay of a checkout that already landed returns the original sale
        // rather than ringing it up again.
        if ($offlineId) {
            $existing = PosSale::with(['items', 'payments'])->where('reference', $reference)->first();

            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        try {
            // Five attempts, not one. A deadlock is transient by definition:
            // MySQL kills one of the two transactions precisely so the other can
            // finish, and the killed one succeeds on a retry. Laravel re-runs
            // the closure for exactly this class of error.
            $sale = DB::transaction(function () use ($request, $adjustStock, $priceFloor, $vendor, $revenue, $reference) {
            $subtotal = collect($request->items)->sum(function ($item) {
                $lineTotal = $item['unit_price'] * $item['quantity'];
                return $lineTotal - ($item['discount_amount'] ?? 0);
            });

            $cartDiscount = (float) ($request->discount_amount ?? 0);

            // Prices arrive from the till, so they're a claim rather than a
            // fact — nothing above this point stops a client posting any price
            // it likes. Checked on the pre-VAT goods value: VAT is collected on
            // the customer's behalf, not margin the store gets to give away.
            $priceFloor->guard($vendor, $request->items, $subtotal - $cartDiscount);

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
                'reference'               => $reference,
                'vendor_id'               => $request->vendor_id,
                // The branch this till stands in, derived from the cashier's
                // assignment — the POS has no panel session to read.
                'store_id'                => TillStore::resolve($request->user(), (int) $request->vendor_id),
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

            // A sale paid entirely on credit is not a split, but it still gets a
            // tender row. Every downstream reader then answers "how much of this
            // walked out unpaid?" the same way — by summing debt tenders —
            // instead of special-casing the sale-level columns.
            if ($request->payment_method === 'debt') {
                PosSalePayment::create([
                    'pos_sale_id' => $sale->id,
                    'method'      => 'debt',
                    'amount'      => $total,
                    'reference'   => null,
                ]);
            }

            // Cost is read once up front rather than per line: profit reporting
            // needs what each item cost AT THIS MOMENT, and a later restock must
            // not retroactively change what this sale earned.
            $costPrices = Product::whereIn('id', collect($request->items)->pluck('product_id'))
                ->pluck('cost_price', 'id');

            foreach ($request->items as $item) {
                $lineDiscount = (float) ($item['discount_amount'] ?? 0);
                $lineTotal    = ($item['unit_price'] * $item['quantity']) - $lineDiscount;

                PosSaleItem::create([
                    'pos_sale_id'  => $sale->id,
                    'product_id'   => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_sku'  => $item['product_sku'] ?? null,
                    'unit_price'   => $item['unit_price'],
                    'unit_cost'    => $costPrices[$item['product_id']] ?? null,
                    'quantity'     => $item['quantity'],
                    'discount_amount' => $lineDiscount,
                    'total'        => $lineTotal,
                ]);

            }

            // Stock is deducted in a second pass, ordered by product id.
            //
            // AdjustStockAction takes a row lock on each product. Walking the
            // cart in its own order means two tills selling the same two
            // products in opposite orders each hold what the other needs — a
            // deadlock, which MySQL resolves by killing one sale outright. That
            // is the "Serialization failure: 1213" the cashiers were seeing.
            // A single agreed order makes the cycle impossible to form.
            $stockOrder = collect($request->items)->sortBy('product_id')->values();

            foreach ($stockOrder as $item) {
                // Deduct physical stock immediately (POS = item leaves the shelf now)
                $adjustStock->execute(
                    productId: $item['product_id'],
                    quantityChanged: -$item['quantity'],
                    transactionType: 'pos_sale',
                    userId: $request->user()->id,
                    reference: $sale->reference,
                    description: "POS sale — {$item['product_name']} x{$item['quantity']}",
                    // Off the shelf the customer is standing at, not the
                    // vendor's default branch.
                    store: $sale->store_id,
                );
            }

            // Update customer spend stats
            if ($sale->customer_id) {
                PosCustomer::where('id', $sale->customer_id)->increment('total_spent', $total);
                PosCustomer::where('id', $sale->customer_id)->increment('total_transactions');
            }

            // Inside the sale's transaction: goods leaving on credit and the
            // debt that records it must land together or not at all.
            app(ChargeCustomerDebtAction::class)->execute($sale);

            $revenue->execute($sale);

            return $sale;
            }, 5);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A business-rule failure (e.g. insufficient stock) inside the
            // transaction previously surfaced as an opaque 500 with no message
            // in production — the till's frontend then silently treated that
            // identically to a network drop and queued the sale offline forever,
            // never telling the cashier the goods were never actually deducted
            // or recorded. Report it plainly instead so the till can react to it now.
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'sale_rejected',
            ], 422);
        }

        return response()->json($sale->load(['items', 'payments']), 201);
    }

    public function void(Request $request, PosSale $sale, AdjustStockAction $adjustStock, RecognizePosSaleRevenueAction $revenue): JsonResponse
    {
        $user   = $request->user();
        $vendor = \App\Models\Vendor::find($sale->vendor_id);

        if (! $vendor?->isOwner($user) && ! $user->hasVendorPermission($sale->vendor_id, 'void_sale')) {
            return response()->json(['message' => 'Insufficient permissions to void a sale.'], 403);
        }

        if ($sale->status !== 'completed') {
            return response()->json(['message' => 'Only completed sales can be voided.'], 422);
        }

        DB::transaction(function () use ($sale, $adjustStock, $request, $revenue) {
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

            $revenue->reverseForVoid($sale);

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

    public function processReturn(Request $request, PosSale $sale, AdjustStockAction $adjustStock, RecognizePosSaleRevenueAction $revenue): JsonResponse
    {
        $user   = $request->user();
        $vendor = \App\Models\Vendor::find($sale->vendor_id);

        if (! $vendor?->isOwner($user) && ! $user->hasVendorPermission($sale->vendor_id, 'process_return')) {
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

        $return = DB::transaction(function () use ($request, $sale, $adjustStock, $alreadyReturned, $revenue) {
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

            $revenue->reverseForReturn($sale, $posReturn);

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

    // A cashier's own sales — used for the till's "Sales History" screen and
    // receipt reprints. Deliberately scoped to the authenticated cashier only,
    // not every sale for the vendor — that's the owner/manager's Sales Report.
    public function myHistory(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
        ]);

        $sales = PosSale::with(['items', 'payments'])
            ->where('vendor_id', $request->vendor_id)
            ->where('cashier_id', $request->user()->id)
            ->latest('completed_at')
            ->paginate(20);

        return response()->json($sales);
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
                ->orWhereHas('roles', fn ($q)=> $q
                    ->where('name', 'inventory_manager')
                    ->where('team_id', $request->vendor_id)
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
