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

        activity()->causedBy($request->user())
            ->withProperties(['terminal' => $session->terminal_id, 'opening_float' => $session->opening_float])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Opened POS session');

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
        $user   = $request->user();
        $vendor = \App\Models\Vendor::find($session->vendor_id);

        if (! $vendor?->isOwner($user) && ! $user->hasVendorPermission($session->vendor_id, 'close_pos_session')) {
            return response()->json(['message' => 'Insufficient permissions to close a session.'], 403);
        }

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

        activity()->causedBy($request->user())
            ->withProperties([
                'total_sales'  => $report->total_sales,
                'cash_counted' => $report->cash_counted,
                'variance'     => $report->cash_variance,
            ])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Closed POS session');

        return response()->json($report);
    }

    public function zReport(PosSession $session): JsonResponse
    {
        $report = $session->zReport;

        if (! $report) {
            return response()->json(['message' => 'Z-Report not yet generated. Close the session first.'], 404);
        }

        return response()->json($report->load('cashier:id,name'));
    }

    // ── Suspended sales ─────────────────────────────────────────────
    //
    // No fixed slot count — any cashier on the vendor can hold as many sales
    // as needed and see every other cashier's held sales too, since the
    // whole point is "pause this one, come back to it later" rather than a
    // per-cashier scratchpad.

    public function listSuspended(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $sales = PosSuspendedSale::where('vendor_id', $request->vendor_id)
            ->with('customer:id,name,phone')
            ->oldest()
            ->get();

        return response()->json($sales);
    }

    public function suspend(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'   => 'required|integer',
            'label'       => 'nullable|string|max:80',
            'customer_id' => 'nullable|integer',
            'cart_data'   => 'required|array',
        ]);

        $suspended = PosSuspendedSale::create([
            'vendor_id'   => $request->vendor_id,
            'cashier_id'  => $request->user()->id,
            'customer_id' => $request->customer_id,
            'label'       => $request->label,
            'cart_data'   => $request->cart_data,
        ]);

        return response()->json($suspended, 201);
    }

    public function resume(Request $request, PosSuspendedSale $suspendedSale): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);
        abort_unless($suspendedSale->vendor_id === (int) $request->vendor_id, 404);

        $data = $suspendedSale->toArray();
        $suspendedSale->delete();

        return response()->json($data);
    }

    public function clearSuspended(Request $request, PosSuspendedSale $suspendedSale): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);
        abort_unless($suspendedSale->vendor_id === (int) $request->vendor_id, 404);

        $suspendedSale->delete();

        return response()->json(['message' => 'Held sale cleared.']);
    }
}
