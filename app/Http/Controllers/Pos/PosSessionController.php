<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\PosSuspendedSale;
use App\Models\PosZReport;
use App\Services\Cash\CashUpExpectation;
use App\Services\Inventory\TillStore;
use App\Support\Pos\BusinessDate;
use App\Support\Pos\CashUpBreakdown;
use App\Support\Pos\TillProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's trading day, from the till.
 *
 * One session per cashier per branch per day: opened with a counted float,
 * closed with a counted drawer and a Moniepoint reading. The session and the
 * cash-up are the same record — two rows for one day would be two places for
 * that day to disagree about itself.
 *
 * Blind entry is the controlling rule: the server must not reveal an expected
 * figure or a variance until both counts are in, or the count stops being a
 * count. Every response here goes through PosSession::toBlindArray(), which
 * withholds those fields until the counts land, rather than each method being
 * trusted to remember.
 *
 * Rectifying a difference is deliberately not here. Only a manager may explain a
 * gap, from the panel, so the person a shortage names is never the person who
 * writes it off. What the cashier can do is say what happened, in free text, on
 * close.
 */
class PosSessionController extends Controller
{
    /**
     * Start the day, or rejoin the one already running.
     *
     * It used to close any open session for this cashier and create a new one,
     * with a float of zero and no screen at all — so every variance was wrong by
     * whatever was in the drawer when they arrived, and the same cashier signing
     * in on a second till silently closed the session on the first. Now the day
     * is shared: a second device joins the session rather than ending it.
     */
    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'       => 'required|integer',
            'opening_float'   => 'required|numeric|min:0',
            'terminal_id'     => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        if ($storeId === null) {
            return $this->noBranch();
        }

        $businessDate = BusinessDate::today();
        $existing = PosSession::forDay($request->user()->id, $storeId, $businessDate);

        if ($existing) {
            // Already counted and submitted — this day is finished, and a second
            // float would be a second opening for a drawer that has been closed.
            if (! $existing->isOpen()) {
                return response()->json([
                    'message' => 'This day has already been cashed up.',
                    'session' => $existing->toBlindArray(),
                ], 409);
            }

            return $this->openedResponse($request, $existing, 200);
        }

        try {
            $session = PosSession::create([
                'vendor_id'            => (int) $request->vendor_id,
                'store_id'             => $storeId,
                'cashier_id'           => $request->user()->id,
                'terminal_id'          => $request->terminal_id ?? 'default',
                'business_date'        => $businessDate,
                'opening_float'        => (float) $request->opening_float,
                'opened_at'            => now(),
                'status'               => PosSession::STATUS_OPEN,
                'open_idempotency_key' => $request->idempotency_key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two tills opening at once. The unique key settled it; whichever
            // won is the day, and the loser joins it.
            $session = PosSession::forDay($request->user()->id, $storeId, $businessDate);

            return $session
                ? $this->openedResponse($request, $session, 200)
                : $this->noBranch();
        }

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->withProperties(['terminal' => $session->terminal_id, 'opening_float' => (float) $session->opening_float])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Opened till session');

        return $this->openedResponse($request, $session, 201);
    }

    /** The day in progress at this till, plus anything left open behind it. */
    public function active(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        if ($storeId === null) {
            return $this->noBranch();
        }

        $session = PosSession::openFor($request->user()->id, $storeId);

        return response()->json([
            'business_date' => BusinessDate::today(),
            'store_id'      => $storeId,
            'session'       => $session?->toBlindArray(),
            // Days this cashier left open behind them. Offered back rather than
            // left hanging — a forgotten day would otherwise stay open for ever
            // with its float unaccounted for.
            'unclosed'      => $this->unclosed($request->user()->id, $storeId),
        ]);
    }

    /**
     * Count the drawer, read the terminal, and finish the day.
     *
     * Both counts arrive together and the expected figures are computed and
     * frozen in the same transaction, so there is no moment at which the cashier
     * could have seen one and adjusted the other.
     *
     * Deliberately not gated on close_pos_session. Counting the money you took
     * is the job rather than a privilege — storekeepers sell and do not hold that
     * permission — so the gate is identity: your own drawer, and nobody else's.
     */
    public function close(Request $request, PosSession $session): JsonResponse
    {
        $request->validate([
            'counted_cash'     => 'required|numeric|min:0',
            'counted_terminal' => 'required|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
            'idempotency_key'  => 'nullable|string|max:120',
        ]);

        if ((int) $session->cashier_id !== $request->user()->id) {
            abort(404);
        }

        if (! $session->isOpen()) {
            $sameRequest = filled($request->idempotency_key)
                && $session->close_idempotency_key === $request->idempotency_key;

            return response()->json([
                'message' => $sameRequest ? 'Already closed.' : 'This day has already been cashed up.',
                'session' => $session->toBlindArray(),
                'report'  => $session->zReport,
            ], $sameRequest ? 200 : 409);
        }

        [$breakdown, $report] = DB::transaction(function () use ($request, $session) {
            // Locked in before anything is revealed, and computed with no
            // rectifications because none can exist yet — only a manager may add
            // them, and only after this point.
            $breakdown = app(CashUpExpectation::class)->for($session);

            $countedCash = (float) $request->counted_cash;
            $countedTerminal = (float) $request->counted_terminal;

            $session->update([
                'counted_cash'          => $countedCash,
                'counted_terminal'      => $countedTerminal,
                // Kept in step with counted_cash rather than left behind: it is
                // the same figure under the name this table used before cash-up.
                'closing_float'         => $countedCash,
                'expected_cash'         => $breakdown->expectedCash,
                'expected_terminal'     => $breakdown->expectedTerminal,
                'cash_variance'         => $breakdown->cashVarianceAgainst($countedCash),
                'terminal_variance'     => $breakdown->terminalVarianceAgainst($countedTerminal),
                'breakdown'             => $breakdown->toArray(),
                'notes'                 => $request->notes,
                'status'                => PosSession::STATUS_PENDING_REVIEW,
                'closed_at'             => now(),
                'close_idempotency_key' => $request->idempotency_key,
            ]);

            return [$breakdown, $this->writeZReport($session->refresh(), $breakdown)];
        });

        $session->refresh();

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->withProperties([
                'counted_cash'      => (float) $session->counted_cash,
                'counted_terminal'  => (float) $session->counted_terminal,
                'cash_variance'     => (float) $session->cash_variance,
                'terminal_variance' => (float) $session->terminal_variance,
            ])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Cashed up till session');

        // The counts are in, so the figures may now be shown — and the working
        // with them, because "you are short" without the arithmetic is not
        // something a cashier can check or a manager can defend.
        return response()->json([
            'session'   => $session->toBlindArray(),
            'breakdown' => $breakdown->toArray(),
            'warnings'  => $breakdown->warnings(),
            'report'    => $report,
        ]);
    }

    /** This cashier's recent days at this branch. */
    public function history(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        if ($storeId === null) {
            return $this->noBranch();
        }

        $sessions = PosSession::query()
            ->forStore($storeId)
            ->forCashier($request->user()->id)
            ->whereNotNull('business_date')
            ->with('rectifications')
            ->orderByDesc('business_date')
            ->limit(14)
            ->get();

        return response()->json([
            'sessions' => $sessions->map(fn (PosSession $s) => $s->toBlindArray() + [
                // What is still unexplained today, which is not what was frozen
                // at close once a manager has rectified part of it.
                'resolved_cash_variance'     => $s->countsSubmitted() ? $s->resolvedCashVariance() : null,
                'resolved_terminal_variance' => $s->countsSubmitted() ? $s->resolvedTerminalVariance() : null,
            ]),
        ]);
    }

    public function zReport(PosSession $session): JsonResponse
    {
        $report = $session->zReport;

        if (! $report) {
            return response()->json(['message' => 'Z-Report not yet generated. Cash up first.'], 404);
        }

        return response()->json($report->load('cashier:id,name'));
    }

    /**
     * The signed slip.
     *
     * Written from the session's frozen figures rather than recomputed, so a
     * reprint is the same piece of paper it was the first time.
     *
     * Its sales are gathered the way the reconciliation gathers them — by
     * cashier, branch and business date — rather than by pos_session_id. Sales
     * replayed through the offline sync endpoint carry no session id at all, so
     * the old slip silently omitted every sale rung while the till was offline.
     */
    private function writeZReport(PosSession $session, CashUpBreakdown $breakdown): PosZReport
    {
        [$from, $to] = BusinessDate::boundsFor($session->business_date->toDateString());

        $sales = PosSale::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('store_id', $session->store_id)
            ->where('cashier_id', $session->cashier_id)
            ->where('status', '!=', 'voided')
            ->whereBetween('completed_at', [$from, $to])
            ->get();

        $returns = PosReturn::query()
            ->where('vendor_id', $session->vendor_id)
            ->where('cashier_id', $session->cashier_id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        return PosZReport::updateOrCreate(
            ['pos_session_id' => $session->id],
            [
                'vendor_id'           => $session->vendor_id,
                'cashier_id'          => $session->cashier_id,
                'report_date'         => $session->business_date->toDateString(),
                'cash_sales'          => $sales->where('payment_method', 'cash')->sum('total'),
                'card_sales'          => $sales->where('payment_method', 'card')->sum('total'),
                'bank_transfer_sales' => $sales->where('payment_method', 'bank_transfer')->sum('total'),
                'total_sales'         => $sales->sum('total'),
                'total_vat'           => $sales->sum('vat_amount'),
                'total_discounts'     => $sales->sum('discount_amount'),
                'total_returns'       => $returns->sum('refund_amount'),
                'transaction_count'   => $sales->count(),
                'return_count'        => $returns->count(),
                'opening_float'       => $session->opening_float,
                'cash_expected'       => $session->expected_cash,
                'cash_counted'        => $session->counted_cash,
                'cash_variance'       => $session->cash_variance,
                'terminal_expected'   => $session->expected_terminal,
                'terminal_counted'    => $session->counted_terminal,
                'terminal_variance'   => $session->terminal_variance,
                'notes'               => $session->notes,
                'generated_at'        => now(),
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function unclosed(int $cashierId, int $storeId): array
    {
        return PosSession::query()
            ->forStore($storeId)
            ->forCashier($cashierId)
            ->open()
            ->whereNotNull('business_date')
            ->whereDate('business_date', '<', BusinessDate::today())
            ->orderBy('business_date')
            ->get()
            ->map(fn (PosSession $s) => $s->toBlindArray())
            ->all();
    }

    /**
     * Opening a session is also the once-a-shift moment the till refreshes the
     * vendor's receipt layout, so a shop that changed its receipt is not waiting
     * for a cashier to happen to log out before it reaches the paper.
     */
    private function openedResponse(Request $request, PosSession $session, int $status): JsonResponse
    {
        return response()->json([
            'session'         => $session->toBlindArray(),
            'vendor_settings' => TillProfile::for($request->user(), $session->vendor),
        ], $status);
    }

    private function noBranch(): JsonResponse
    {
        return response()->json([
            'message' => 'This till is not assigned to a branch, so it cannot open a session. Ask your manager to assign you to a store.',
        ], 422);
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
