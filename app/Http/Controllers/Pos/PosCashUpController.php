<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashUpSession;
use App\Services\Cash\CashUpExpectation;
use App\Services\Inventory\TillStore;
use App\Support\Pos\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The till's side of the end-of-day cash-up.
 *
 * Two writes only: open the day with a counted float, and close it with a
 * counted drawer and a terminal total. Rectifying a difference is deliberately
 * not here — only a manager may explain a gap, from the review screen, so the
 * person the shortage names is never the person who writes off the shortage.
 * What the cashier can do is say what happened, in free text, on close.
 *
 * Blind entry is the controlling rule (locked decision): the server must not
 * reveal an expected figure or a variance until both counts are in, or the
 * count stops being a count. Every response here goes through
 * CashUpSession::toBlindArray(), which withholds those fields until the counts
 * land, rather than each method being trusted to remember.
 *
 * Unlike PosCashController this works offline-tolerantly: a till that has been
 * disconnected should still be able to open and close, and the figures it is
 * measured against are computed server-side from whatever has synced. A sale
 * still sitting in IndexedDB will show as a shortage — which is why close
 * reports how many sales it actually saw, so a manager can tell an unsynced
 * till from a light drawer.
 */
class PosCashUpController extends Controller
{
    /** The day in progress at this till, if there is one. */
    public function current(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        if ($storeId === null) {
            return $this->noBranch();
        }

        $session = CashUpSession::openFor($request->user()->id, $storeId);

        return response()->json([
            'business_date' => BusinessDate::today(),
            'store_id'      => $storeId,
            'session'       => $session?->toBlindArray(),
            // Days this cashier left open behind them. The till offers to close
            // them rather than letting them hang forever, which is what happens
            // when somebody forgets and simply opens again tomorrow.
            'unclosed'      => $this->unclosed($request->user()->id, $storeId),
        ]);
    }

    /**
     * Start the day by counting the float into the drawer.
     *
     * The float is required: a closing variance measured against an unknown
     * opening is not a variance, it is a guess.
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

        // A retried open must return the day it already started, not a second
        // one and not an error the till has no way to act on.
        $existing = CashUpSession::forDay($request->user()->id, $storeId, $businessDate);

        if ($existing) {
            return response()->json([
                'message' => $existing->isOpen()
                    ? 'This cash-up is already open.'
                    : 'This day has already been closed.',
                'session' => $existing->toBlindArray(),
            ], $existing->isOpen() ? 200 : 409);
        }

        try {
            $session = CashUpSession::create([
                'vendor_id'           => (int) $request->vendor_id,
                'store_id'            => $storeId,
                'cashier_id'          => $request->user()->id,
                'terminal_id'         => $request->terminal_id,
                'business_date'       => $businessDate,
                'opening_float'       => (float) $request->opening_float,
                'opened_at'           => now(),
                'status'              => CashUpSession::STATUS_OPEN,
                'open_idempotency_key' => $request->idempotency_key,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries landing at once. The unique key settled it; return
            // whichever won rather than failing the till.
            return response()->json([
                'message' => 'This cash-up is already open.',
                'session' => CashUpSession::forDay($request->user()->id, $storeId, $businessDate)?->toBlindArray(),
            ]);
        }

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->withProperties(['opening_float' => (float) $session->opening_float, 'store_id' => $storeId])
            ->tap(fn ($a) => $a->vendor_id = $session->vendor_id)
            ->log('Opened cash-up');

        return response()->json(['session' => $session->toBlindArray()], 201);
    }

    /**
     * Count the drawer and read the terminal.
     *
     * Both counts arrive together and the expected figures are computed and
     * frozen in the same transaction, so there is no moment at which the cashier
     * could have seen one and adjusted the other.
     */
    public function close(Request $request, CashUpSession $session): JsonResponse
    {
        $request->validate([
            'counted_cash'     => 'required|numeric|min:0',
            'counted_terminal' => 'required|numeric|min:0',
            'notes'            => 'nullable|string|max:1000',
            'idempotency_key'  => 'nullable|string|max:120',
        ]);

        // Your own drawer only. Not a permission — counting the money you took
        // is the job, not a privilege — but emphatically not somebody else's.
        if ((int) $session->cashier_id !== $request->user()->id) {
            abort(404);
        }

        if (! $session->isOpen()) {
            // A retried close returns the same answer rather than an error the
            // offline queue would keep replaying forever.
            $sameRequest = filled($request->idempotency_key)
                && $session->close_idempotency_key === $request->idempotency_key;

            return response()->json([
                'message' => $sameRequest ? 'Already closed.' : 'This cash-up has already been closed.',
                'session' => $session->toBlindArray(),
            ], $sameRequest ? 200 : 409);
        }

        $breakdown = DB::transaction(function () use ($request, $session) {
            // Locked in before anything is revealed: computed with no
            // rectifications, because none exist yet — only a manager may add
            // them, and only after this point.
            $breakdown = app(CashUpExpectation::class)->for($session);

            $session->update([
                'counted_cash'          => (float) $request->counted_cash,
                'counted_terminal'      => (float) $request->counted_terminal,
                'expected_cash'         => $breakdown->expectedCash,
                'expected_terminal'     => $breakdown->expectedTerminal,
                'cash_variance'         => $breakdown->cashVarianceAgainst((float) $request->counted_cash),
                'terminal_variance'     => $breakdown->terminalVarianceAgainst((float) $request->counted_terminal),
                'breakdown'             => $breakdown->toArray(),
                'notes'                 => $request->notes,
                'status'                => CashUpSession::STATUS_PENDING_REVIEW,
                'closed_at'             => now(),
                'close_idempotency_key' => $request->idempotency_key,
            ]);

            return $breakdown;
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
            ->log('Closed cash-up');

        // The counts are in, so the figures may now be shown — and the working
        // with them, because "you are short" without the arithmetic is not
        // something a cashier can check or a manager can defend.
        return response()->json([
            'session'   => $session->toBlindArray(),
            'breakdown' => $breakdown->toArray(),
            'warnings'  => $breakdown->warnings(),
        ]);
    }

    /** This cashier's recent cash-ups at this branch. */
    public function history(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        if ($storeId === null) {
            return $this->noBranch();
        }

        $sessions = CashUpSession::query()
            ->forStore($storeId)
            ->forCashier($request->user()->id)
            ->orderByDesc('business_date')
            ->limit(14)
            ->get();

        return response()->json([
            'sessions' => $sessions->map(fn (CashUpSession $s) => $s->toBlindArray() + [
                // What is still unexplained today, which is not what was frozen
                // at close once a manager has rectified part of it.
                'resolved_cash_variance'     => $s->countsSubmitted() ? $s->resolvedCashVariance() : null,
                'resolved_terminal_variance' => $s->countsSubmitted() ? $s->resolvedTerminalVariance() : null,
            ]),
        ]);
    }

    /**
     * Days this cashier opened and never closed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unclosed(int $cashierId, int $storeId): array
    {
        return CashUpSession::query()
            ->forStore($storeId)
            ->forCashier($cashierId)
            ->open()
            ->whereDate('business_date', '<', BusinessDate::today())
            ->orderBy('business_date')
            ->get()
            ->map(fn (CashUpSession $s) => $s->toBlindArray())
            ->all();
    }

    /**
     * A till standing in no branch cannot reconcile a drawer, because there is
     * no drawer to reconcile. Says so plainly rather than guessing at a store.
     */
    private function noBranch(): JsonResponse
    {
        return response()->json([
            'message' => 'This till is not assigned to a branch, so it cannot run a cash-up. Ask your manager to assign you to a store.',
        ], 422);
    }
}
