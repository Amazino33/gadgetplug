<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCustomerController extends Controller
{
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

        $customer = PosCustomer::firstOrCreate(
            ['vendor_id' => $request->vendor_id, 'phone' => $request->phone],
            ['name' => $request->name, 'email' => $request->email]
        );

        return response()->json($customer, 201);
    }
}
