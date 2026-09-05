<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use App\Services\Pos\CustomerDebtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCustomerController extends Controller
{
    /**
     * What this customer currently owes.
     *
     * Read-only and informational — the till shows it so staff can judge
     * exposure before extending more credit. It is deliberately not a gate:
     * the person behind the counter knows things the balance does not.
     */
    public function outstanding(PosCustomer $customer): JsonResponse
    {
        return response()->json([
            'customer_id' => $customer->id,
            'outstanding' => app(CustomerDebtService::class)->outstanding($customer->id),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'q'         => 'nullable|string',
        ]);

        $customers = PosCustomer::query()
            ->where('vendor_id', $request->vendor_id)
            ->when($request->q, fn ($query, $q) => $query
                ->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
            )
            ->orderByDesc('total_spent')
            ->limit(30)
            ->get(['id', 'name', 'phone', 'email', 'total_spent', 'total_transactions']);

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id' => 'required|integer',
            'name'      => 'required|string|max:100',
            'phone'     => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:100',
        ]);

        // Matching on phone only works when there IS one. firstOrCreate with a
        // null phone becomes "where phone is null", which matches ANY
        // phone-less customer this vendor has — so the second walk-in with no
        // number came back as the first one, and a credit sale would land on a
        // stranger's ledger while looking perfectly consistent.
        //
        // With a number, reuse is still right and still what the till wants: the
        // same person should not accumulate three records and three balances.
        $customer = filled($request->phone)
            ? PosCustomer::firstOrCreate(
                ['vendor_id' => $request->vendor_id, 'phone' => $request->phone],
                ['name' => $request->name, 'email' => $request->email],
            )
            : PosCustomer::create([
                'vendor_id' => $request->vendor_id,
                'name'      => $request->name,
                'email'     => $request->email,
                'phone'     => null,
            ]);

        return response()->json($customer, 201);
    }
}
