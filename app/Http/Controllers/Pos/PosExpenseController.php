<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Finance\RecordExpenseAction;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Inventory\TillStore;
use App\Support\Pos\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Money paid out of the drawer, recorded at the counter when it happens.
 *
 * A cashier hands the driver ₦3,000 at eleven in the morning. Recording it then,
 * while it is fresh and while the receipt is in their hand, is the whole control:
 * an expense reconstructed at eight in the evening — after seeing the drawer is
 * short — is a story, and one nobody can check. This is what shops have always
 * done with a paid-out slip in the till.
 *
 * It writes an ordinary Expense, so it lands on the same dashboard the panel
 * records expenses to. There is no separate till-expense concept to reconcile
 * later, and no second list for an owner to remember to look at.
 *
 * It also lowers what the drawer should hold at cash-up — necessarily, because
 * the money physically left. Without that the cashier would be told they were
 * short by exactly the amount they had just paid out and recorded.
 */
class PosExpenseController extends Controller
{
    /**
     * What a till may spend on.
     *
     * Advertising is deliberately absent: nobody buys Facebook ads out of a
     * cash drawer, and offering it only invites a miscategorised row that the
     * marketing figures would then have to carry.
     */
    private const TILL_CATEGORIES = ['logistics_other', 'other'];

    /** What this cashier has already paid out today, so the till can show it back. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => 'required|integer']);

        $storeId = TillStore::resolve($request->user(), (int) $request->vendor_id);

        $expenses = Expense::query()
            ->where('vendor_id', (int) $request->vendor_id)
            ->where('created_by', $request->user()->id)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereDate('incurred_at', BusinessDate::today())
            ->latest('id')
            ->get(['id', 'category', 'amount', 'description', 'created_at']);

        return response()->json([
            'categories' => collect(self::TILL_CATEGORIES)
                ->mapWithKeys(fn ($key) => [$key => $this->label($key)])
                ->all(),
            'expenses'   => $expenses,
            'total'      => round((float) $expenses->sum('amount'), 2),
        ]);
    }

    public function store(Request $request, RecordExpenseAction $record): JsonResponse
    {
        $request->validate([
            'vendor_id'   => 'required|integer',
            'amount'      => 'required|numeric|min:0.01',
            'category'    => 'required|string|in:'.implode(',', self::TILL_CATEGORIES),
            'description' => 'nullable|string|max:255',
        ]);

        $vendorId = (int) $request->vendor_id;
        $account = RecordExpenseAction::cashAccountFor($vendorId);

        if (! $account) {
            // Refused rather than recorded unposted. The money has left the
            // drawer either way, and an expense that never reaches the accounts
            // would leave the books believing the shop still has it.
            return response()->json([
                'message' => 'This shop has no cash account set up, so a drawer payout cannot be recorded. Ask your manager.',
            ], 422);
        }

        try {
            $expense = $record->execute(
                vendorId: $vendorId,
                category: $request->category,
                amount: (float) $request->amount,
                recordedBy: $request->user(),
                account: $account,
                description: $request->description,
                storeId: TillStore::resolve($request->user(), $vendorId),
                // The shop's trading day, so a payout at half past midnight
                // lands on the day the cash-up will look for it.
                incurredAt: BusinessDate::today(),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'expense' => $expense->only(['id', 'category', 'amount', 'description', 'created_at']),
        ], 201);
    }

    private function label(string $key): string
    {
        return match ($key) {
            'logistics_other' => 'Transport / delivery',
            default           => 'Something else',
        };
    }
}
