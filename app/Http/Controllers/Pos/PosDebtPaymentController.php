<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\RecordDebtRepaymentAction;
use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Models\PosDebtPayment;
use App\Services\Inventory\TillStore;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * A customer paying down what they owe, recorded at the till the moment it
 * happens — the same "record it now, while the receipt is in hand" control
 * PosExpenseController uses for money leaving the drawer, mirrored for money
 * arriving into it.
 */
class PosDebtPaymentController extends Controller
{
    public function store(Request $request, PosCustomer $customer, RecordDebtRepaymentAction $record): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'amount'    => 'required|numeric|min:0.01',
            'method'    => 'required|string|in:'.implode(',', PosDebtPayment::METHODS),
            'note'      => 'nullable|string|max:255',
        ]);

        $vendorId = (int) $request->vendor_id;

        try {
            $payment = $record->execute(
                customer: $customer,
                amount: (float) $request->amount,
                method: $request->method,
                collectedBy: $request->user(),
                storeId: TillStore::resolve($request->user(), $vendorId),
                note: $request->note,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment'     => $payment->only(['id', 'method', 'amount', 'note', 'collected_at']),
            'outstanding' => app(CustomerDebtService::class)->outstanding($customer->id),
        ], 201);
    }
}
