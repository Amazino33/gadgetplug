<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Models\CashSubmission;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How much cash a person should be holding, and what they have handed over.
 *
 * Nothing is stored. The figure is always taken in minus handed over, derived
 * from the sales themselves, so it cannot drift from the takings that produced
 * it — the same rule the picking ledger follows.
 *
 * Deliberately a running balance per person and branch rather than per session.
 * A storekeeper hands cash over when the owner turns up, not when a shift ends,
 * and tying the figure to sessions would leave money unaccounted for between
 * them. Session close still does its own count for the Z-report; the two answer
 * different questions and neither replaces the other.
 *
 * The opening float is not counted. This is money the till has TAKEN, not
 * everything in the drawer — the float belongs to the shop and goes back at
 * close, so counting it would ask a cashier to hand over money that was never
 * theirs to hand.
 */
class CashDrawer
{
    /**
     * Cash this person has taken at this branch and not yet handed over.
     */
    public static function expectedFrom(int $vendorId, int $storeId, int $userId): float
    {
        return round(
            self::takings($vendorId, $storeId, $userId) - self::submitted($vendorId, $storeId, $userId),
            2,
        );
    }

    /**
     * Every naira of cash that has passed into this person's hands here.
     *
     * Cash sales are measured as tendered minus change, which is what actually
     * stayed in the drawer — the sale total would overstate it on every sale
     * where change was given.
     */
    public static function takings(int $vendorId, int $storeId, int $userId): float
    {
        $cashSales = (float) PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('store_id', $storeId)
            ->where('cashier_id', $userId)
            ->where('status', '!=', 'voided')
            ->where('payment_method', 'cash')
            ->selectRaw('COALESCE(SUM(amount_tendered - change_given), 0) as cash')
            ->value('cash');

        // A split sale's cash leg is real cash in the drawer even though the
        // sale itself is not a cash sale. Change on a split is always given in
        // cash, so it comes off here too.
        $splitCash = (float) PosSalePayment::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.store_id', $storeId)
            ->where('pos_sales.cashier_id', $userId)
            ->where('pos_sales.status', '!=', 'voided')
            ->where('pos_sales.payment_method', 'split')
            ->where('pos_sale_payments.method', 'cash')
            ->selectRaw('COALESCE(SUM(pos_sale_payments.amount - pos_sales.change_given), 0) as cash')
            ->value('cash');

        // Money handed back out of the same drawer.
        $refunds = (float) PosReturn::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_returns.original_sale_id')
            ->where('pos_returns.vendor_id', $vendorId)
            ->where('pos_returns.cashier_id', $userId)
            ->where('pos_sales.store_id', $storeId)
            ->where('pos_returns.refund_method', 'cash')
            ->sum('pos_returns.refund_amount');

        return round($cashSales + $splitCash - $refunds, 2);
    }

    /** What this person has already handed over here, disputes excluded. */
    public static function submitted(int $vendorId, int $storeId, int $userId): float
    {
        return round((float) CashSubmission::query()
            ->where('vendor_id', $vendorId)
            ->where('store_id', $storeId)
            ->where('submitted_by', $userId)
            ->againstBalance()
            ->sum('amount'), 2);
    }

    /**
     * Everyone holding cash at a branch, biggest first.
     *
     * The owner's view of where the money is — one row per person still
     * carrying takings they have not handed over.
     *
     * @return Collection<int, array{user_id: int, name: string, expected: float}>
     */
    public static function holdingsAt(int $vendorId, int $storeId): Collection
    {
        $cashiers = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('store_id', $storeId)
            ->where('status', '!=', 'voided')
            ->distinct()
            ->pluck('cashier_id')
            ->filter();

        return $cashiers
            ->map(fn ($userId) => [
                'user_id'  => (int) $userId,
                'name'     => DB::table('users')->where('id', $userId)->value('name') ?? 'Unknown',
                'expected' => self::expectedFrom($vendorId, $storeId, (int) $userId),
            ])
            ->filter(fn (array $row) => $row['expected'] > 0.009)
            ->sortByDesc('expected')
            ->values();
    }
}
