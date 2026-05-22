<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosSuspendedSale;
use App\Models\PosZReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosSessionController extends Controller
{
    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'     => 'required|integer',
            'terminal_id'   => 'nullable|string',
            'opening_float' => 'nullable|numeric|min:0',
        ]);

        // Close any stale open session for this terminal
        PosSession::where('vendor_id', $request->vendor_id)
            ->where('cashier_id', $request->user()->id)
            ->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $session = PosSession::create([
            'vendor_id'     => $request->vendor_id,
            'cashier_id'    => $request->user()->id,
            'terminal_id'   => $request->terminal_id ?? 'default',
            'opening_float' => $request->opening_float ?? 0,
            'opened_at'     => now(),
            'status'        => 'open',
        ]);

        return response()->json($session, 201);
    }

    public function active(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $session = PosSession::where('vendor_id', $request->vendor_id)
            ->where('cashier_id', $request->user()->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        return response()->json($session);
    }

    public function close(Request $request, PosSession $session): JsonResponse
    {
        $request->validate(['cash_counted' => 'nullable|numeric|min:0']);

        $report = DB::transaction(function () use ($request, $session) {
            $sales = PosSale::where('pos_session_id', $session->id)
                ->where('status', 'completed')
                ->get();

            $returns = PosReturn::whereIn(
                'original_sale_id',
                $sales->pluck('id')
            )->get();

            $cashSales         = $sales->where('payment_method', 'cash')->sum('total');
            $cardSales         = $sales->where('payment_method', 'card')->sum('total');
            $bankTransferSales = $sales->where('payment_method', 'bank_transfer')->sum('total');
            $totalSales        = $cashSales + $cardSales + $bankTransferSales;
            $totalVat          = $sales->sum('vat_amount');
            $totalDiscounts    = $sales->sum('discount_amount');
            $totalReturns      = $returns->sum('refund_amount');
            $cashFromSales     = $sales->where('payment_method', 'cash')->sum('amount_tendered')
                                 - $sales->where('payment_method', 'cash')->sum('change_given');
            $cashExpected      = (float) $session->opening_float + $cashFromSales;
            $cashCounted       = $request->cash_counted;
            $cashVariance      = $cashCounted !== null ? $cashCounted - $cashExpected : null;

            $report = PosZReport::create([
                'vendor_id'           => $session->vendor_id,
                'pos_session_id'      => $session->id,
                'cashier_id'          => $session->cashier_id,
                'report_date'         => now()->toDateString(),
                'cash_sales'          => $cashSales,
                'card_sales'          => $cardSales,
                'bank_transfer_sales' => $bankTransferSales,
                'total_sales'         => $totalSales,
                'total_vat'           => $totalVat,
                'total_discounts'     => $totalDiscounts,
                'total_returns'       => $totalReturns,
                'transaction_count'   => $sales->count(),
                'return_count'        => $returns->count(),
                'opening_float'       => $session->opening_float,
                'cash_expected'       => $cashExpected,
                'cash_counted'        => $cashCounted,
                'cash_variance'       => $cashVariance,
                'generated_at'        => now(),
            ]);

            $session->update([
                'status'        => 'closed',
                'closed_at'     => now(),
                'closing_float' => $cashCounted,
            ]);

            return $report;
        });

        return response()->json($report);
    }

    public function zReport(Request $request, PosSession $session): JsonResponse
    {
        $report = $session->zReport;

        if (! $report) {
            return response()->json(['message' => 'Z-Report not yet generated. Close the session first.'], 404);
        }

        return response()->json($report->load('cashier:id,name'));
    }

    // ── Suspended sales ─────────────────────────────────────────────

    public function listSuspended(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $slots = PosSuspendedSale::where('vendor_id', $request->vendor_id)
            ->with('customer:id,name,phone')
            ->get();

        return response()->json($slots);
    }

    public function suspend(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'   => 'required|integer',
            'slot'        => 'required|integer|between:1,3',
            'label'       => 'nullable|string|max:80',
            'customer_id' => 'nullable|integer',
            'cart_data'   => 'required|array',
        ]);

        $suspended = PosSuspendedSale::updateOrCreate(
            ['vendor_id' => $request->vendor_id, 'slot' => $request->slot],
            [
                'cashier_id'  => $request->user()->id,
                'customer_id' => $request->customer_id,
                'label'       => $request->label,
                'cart_data'   => $request->cart_data,
            ]
        );

        return response()->json($suspended, 201);
    }

    public function resume(Request $request, int $slot): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $suspended = PosSuspendedSale::where('vendor_id', $request->vendor_id)
            ->where('slot', $slot)
            ->firstOrFail();

        $data = $suspended->toArray();
        $suspended->delete();

        return response()->json($data);
    }

    public function clearSlot(Request $request, int $slot): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        PosSuspendedSale::where('vendor_id', $request->vendor_id)
            ->where('slot', $slot)
            ->delete();

        return response()->json(['message' => 'Slot cleared.']);
    }
}
