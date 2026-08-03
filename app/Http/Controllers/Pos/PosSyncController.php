<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Inventory\AdjustStockAction;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSalePayment;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\Pos\PosPriceFloor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosSyncController extends Controller
{
    /**
     * Accept a batch of offline sales collected in IndexedDB and persist them.
     * Each sale carries a client-generated `offline_id` so we can deduplicate.
     */
    public function sync(Request $request, AdjustStockAction $adjustStock, PosPriceFloor $priceFloor): JsonResponse
    {
        $request->validate([
            'vendor_id'         => 'required|integer',
            'sales'             => 'required|array',
            'sales.*.offline_id'      => 'required|string',
            'sales.*.customer_id'     => 'nullable|integer',
            'sales.*.items'           => 'required|array|min:1',
            'sales.*.payment_method'  => 'required|in:cash,card,bank_transfer,split',
            'sales.*.total'           => 'required|numeric|min:0',
            'sales.*.vat_amount'      => 'nullable|numeric|min:0',
            'sales.*.discount_amount' => 'nullable|numeric|min:0',
            'sales.*.amount_tendered' => 'nullable|numeric|min:0',
            'sales.*.completed_at'    => 'required|date',
            'sales.*.payments'                  => 'required_if:sales.*.payment_method,split|array|min:2',
            'sales.*.payments.*.method'         => 'required_if:sales.*.payment_method,split|in:cash,card,bank_transfer',
            'sales.*.payments.*.amount'         => 'required_if:sales.*.payment_method,split|numeric|min:0.01',
            'sales.*.payments.*.reference'      => 'nullable|string|max:50',
        ]);

        $results = [];
        $vendor  = Vendor::findOrFail($request->vendor_id);

        foreach ($request->sales as $payload) {
            // Skip if already synced (reference derived from offline_id)
            $existingRef = 'POS-' . strtoupper(substr(md5($payload['offline_id']), 0, 8));

            if (PosSale::where('reference', $existingRef)->exists()) {
                $results[] = ['offline_id' => $payload['offline_id'], 'status' => 'duplicate'];
                continue;
            }

            try {
                // The till enforces the floor before completing an offline
                // sale, so reaching here below it means the cached rules were
                // bypassed or tampered with. Re-checked on the pre-VAT goods
                // value, derived from the total so it holds whether or not the
                // client bothered to send a subtotal.
                $priceFloor->guard(
                    $vendor,
                    $payload['items'],
                    (float) $payload['total'] - (float) ($payload['vat_amount'] ?? 0),
                );

                DB::transaction(function () use ($payload, $request, $adjustStock, $existingRef) {
                    $sale = PosSale::create([
                        'reference'               => $existingRef,
                        'vendor_id'               => $request->vendor_id,
                        'pos_session_id'          => null,
                        'cashier_id'              => $request->user()->id,
                        'customer_id'             => $payload['customer_id'] ?? null,
                        'subtotal'                => $payload['subtotal'] ?? $payload['total'],
                        'discount_amount'         => $payload['discount_amount'] ?? 0,
                        'vat_amount'              => $payload['vat_amount'] ?? 0,
                        'total'                   => $payload['total'],
                        'payment_method'          => $payload['payment_method'],
                        'amount_tendered'         => $payload['amount_tendered'] ?? $payload['total'],
                        'change_given'            => max(0, ($payload['amount_tendered'] ?? 0) - $payload['total']),
                        'bank_transfer_reference' => $payload['bank_transfer_reference'] ?? null,
                        'status'                  => 'completed',
                        'synced'                  => true,
                        'synced_at'               => now(),
                        'completed_at'            => $payload['completed_at'],
                    ]);

                    if ($payload['payment_method'] === 'split') {
                        foreach ($payload['payments'] as $p) {
                            PosSalePayment::create([
                                'pos_sale_id' => $sale->id,
                                'method'      => $p['method'],
                                'amount'      => $p['amount'],
                                'reference'   => $p['reference'] ?? null,
                            ]);
                        }
                    }

                    // Best available cost for a sale that happened offline: the
                    // product's cost now. The true cost at the time of the
                    // offline sale isn't recoverable, but recording today's is
                    // closer than leaving it blank for an already-completed sale.
                    $costPrices = Product::whereIn('id', collect($payload['items'])->pluck('product_id'))
                        ->pluck('cost_price', 'id');

                    foreach ($payload['items'] as $item) {
                        PosSaleItem::create([
                            'pos_sale_id'     => $sale->id,
                            'product_id'      => $item['product_id'],
                            'product_name'    => $item['product_name'],
                            'product_sku'     => $item['product_sku'] ?? null,
                            'unit_price'      => $item['unit_price'],
                            'unit_cost'       => $costPrices[$item['product_id']] ?? null,
                            'quantity'        => $item['quantity'],
                            'discount_amount' => $item['discount_amount'] ?? 0,
                            'total'           => $item['total'],
                        ]);

                        $adjustStock->execute(
                            productId: $item['product_id'],
                            quantityChanged: -$item['quantity'],
                            transactionType: 'pos_sale',
                            userId: $request->user()->id,
                            reference: $sale->reference,
                            description: "Offline POS sync — {$item['product_name']} x{$item['quantity']}",
                        );
                    }

                    if ($sale->customer_id) {
                        PosCustomer::where('id', $sale->customer_id)->increment('total_spent', $sale->total);
                        PosCustomer::where('id', $sale->customer_id)->increment('total_transactions');
                    }
                });

                $results[] = ['offline_id' => $payload['offline_id'], 'status' => 'synced', 'reference' => $existingRef];
            } catch (ValidationException $e) {
                // Reported separately from a crash: this sale was understood
                // and refused, and the goods are already off the shelf, so it
                // needs a human to reconcile rather than a blind retry.
                $results[] = [
                    'offline_id' => $payload['offline_id'],
                    'status'     => 'rejected',
                    'message'    => $e->validator->errors()->first(),
                ];
            } catch (\Throwable $e) {
                $results[] = ['offline_id' => $payload['offline_id'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results]);
    }
}
