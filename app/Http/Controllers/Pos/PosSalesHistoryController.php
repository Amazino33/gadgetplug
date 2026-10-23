<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Services\Pos\CashierSalesHistory;
use App\Services\Reporting\ReportPeriod;
use App\Support\Pos\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The till's full sales history: a cashier's own sales over any window,
 * grouped by trading day, with what each day and the whole window add up to.
 *
 * Separate from my-history, which stays the quick "last few sales" list that
 * every cashier can reach to reprint a receipt. This one is behind its own
 * permission, so an owner can withdraw it without taking reprints away.
 *
 * Still the cashier's own sales only — the whole store's takings are the
 * owner's Sales Report, not something a till hands to whoever is signed in.
 */
class PosSalesHistoryController extends Controller
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request, CashierSalesHistory $history): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id' => 'required|integer',
            'preset'    => ['nullable', Rule::in(array_keys(ReportPeriod::PRESETS))],
            'from'      => 'nullable|date_format:Y-m-d',
            'to'        => 'nullable|date_format:Y-m-d',
            'method'    => ['nullable', Rule::in(CashierSalesHistory::FILTER_METHODS)],
            'status'    => ['nullable', Rule::in(CashierSalesHistory::FILTER_STATUSES)],
            'q'         => 'nullable|string|max:100',
            'page'      => 'nullable|integer|min:1',
        ]);

        $user = $request->user();
        $vendorId = (int) $validated['vendor_id'];

        if (! $user->hasVendorPermission($vendorId, 'view_sales_history')) {
            return response()->json(['message' => 'You do not have permission to view the full sales history.'], 403);
        }

        // Worked out on the store's wall clock, so "today" starts at midnight
        // in Lagos and not an hour later.
        $period = ReportPeriod::fromFilters([
            'preset' => $validated['preset'] ?? 'today',
            'from'   => $validated['from'] ?? null,
            'to'     => $validated['to'] ?? null,
        ]);

        $filters = [
            'method' => $validated['method'] ?? null,
            'status' => $validated['status'] ?? null,
            'q'      => $validated['q'] ?? null,
        ];

        $page = $history->sales($vendorId, $user->id, $period->from, $period->to, $filters)
            ->with(['items', 'payments'])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $sales = collect($page->items())->map(fn (PosSale $sale) => array_merge($sale->toArray(), [
            'business_date' => BusinessDate::of($sale->completed_at),
            'cashier_name'  => $user->name,
        ]));

        // Only the days this page touches. The till keeps what it has already
        // been sent, and a day split across two pages costs one extra summary.
        $days = $sales->pluck('business_date')->unique()->mapWithKeys(function (string $date) use ($history, $vendorId, $user, $filters) {
            [$from, $to] = BusinessDate::boundsFor($date);

            return [$date => $history->summary($vendorId, $user->id, $from, $to, $filters)];
        });

        return response()->json([
            'period' => [
                'label' => $period->label,
                'from'  => BusinessDate::of($period->from),
                'to'    => BusinessDate::of($period->to),
            ],
            // The whole window, sent with the first page only: it does not
            // change as the cashier scrolls.
            'summary'      => $page->currentPage() === 1
                ? $history->summary($vendorId, $user->id, $period->from, $period->to, $filters)
                : null,
            'days'         => $days,
            'data'         => $sales->values(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
        ]);
    }
}
