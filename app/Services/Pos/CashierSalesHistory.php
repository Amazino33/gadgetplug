<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSalePayment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * A cashier's own sales over any window, and what they add up to.
 *
 * Backs the till's full sales history. The range summary and every day heading
 * are worked out by summary() and nothing else, so a day's figures can never
 * disagree with the range they sit in.
 *
 * The money follows the cash-up rather than the owner's Sales Report, because
 * the person reading it is the one who counts the drawer:
 *   collected = what came in on cash, card and transfer, VAT included. A mixed
 *               sale is read off its tender rows, and its change comes out of
 *               cash — the same rule CashUpExpectation applies.
 *   credit    = sold on debt. Goods went out, money did not come in.
 *   refunds   = returns this cashier paid out in the window, dated by the
 *               refund itself (the Z report's rule). Store credit is counted
 *               as a refund but not as money out.
 *   revenue   = the Sales Report's definition — net of discount, without VAT,
 *               refunds netted off — shown alongside, never instead.
 */
class CashierSalesHistory
{
    public const METHODS = ['cash', 'card', 'bank_transfer', 'debt'];

    public const MONEY_METHODS = ['cash', 'card', 'bank_transfer'];

    public const FILTER_METHODS = ['cash', 'card', 'bank_transfer', 'debt', 'split'];

    public const FILTER_STATUSES = ['completed', 'voided', 'refunded'];

    /**
     * Every sale this cashier rang at this vendor in the window, filters applied.
     *
     * Dated by completed_at — when the till rang it — so a sale rung offline
     * and uploaded days later still lands on the day it was made.
     *
     * @param  array{method?: ?string, status?: ?string, q?: ?string}  $filters
     */
    public function sales(int $vendorId, int $cashierId, CarbonInterface $from, CarbonInterface $to, array $filters = []): Builder
    {
        $method = $filters['method'] ?? null;
        $status = $filters['status'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));

        return PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('cashier_id', $cashierId)
            ->whereBetween('completed_at', [$from, $to])
            ->when($method === 'split', fn (Builder $q) => $q->where('payment_method', 'split'))
            // A cash filter that missed the cash half of a mixed sale would
            // hide money the cashier knows they took.
            ->when($method && $method !== 'split', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('payment_method', $method)
                ->orWhere(fn (Builder $q) => $q
                    ->where('payment_method', 'split')
                    ->whereHas('payments', fn (Builder $q) => $q->where('method', $method))
                )
            ))
            ->when($status === 'refunded', fn (Builder $q) => $q->whereIn('status', ['refunded', 'partial_refund']))
            ->when($status && $status !== 'refunded', fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('reference', 'like', "%{$search}%")
                ->orWhereHas('items', fn (Builder $q) => $q->where('product_name', 'like', "%{$search}%"))
            ));
    }

    /**
     * What a window's sales add up to, under the same filters as the list.
     *
     * Refunds are left out (null) wherever they cannot honestly be matched to
     * the filter: a search names sales, not refunds; a refund is never voided;
     * and a mixed or credit filter has no refund method to compare against.
     *
     * @param  array{method?: ?string, status?: ?string, q?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function summary(int $vendorId, int $cashierId, CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        $method = $filters['method'] ?? null;
        $base = fn () => $this->sales($vendorId, $cashierId, $from, $to, $filters);

        // One pass over the sales for everything that lives on the sale row.
        $directSql = collect(self::METHODS)
            ->map(fn (string $m) => "COALESCE(SUM(CASE WHEN status != 'voided' AND payment_method = '{$m}' THEN total ELSE 0 END), 0) as direct_{$m}")
            ->implode(', ');

        $row = $base()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status != 'voided' THEN 1 ELSE 0 END), 0) as sales_count,
                COALESCE(SUM(CASE WHEN status != 'voided' THEN subtotal - discount_amount ELSE 0 END), 0) as revenue,
                COALESCE(SUM(CASE WHEN status != 'voided' THEN vat_amount ELSE 0 END), 0) as vat,
                COALESCE(SUM(CASE WHEN status != 'voided' THEN discount_amount ELSE 0 END), 0) as discounts,
                COALESCE(SUM(CASE WHEN status != 'voided' AND payment_method = 'split' THEN change_given ELSE 0 END), 0) as split_change,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN 1 ELSE 0 END), 0) as voided_count,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total ELSE 0 END), 0) as voided_total,
                {$directSql}
            ")
            ->toBase()
            ->first();

        // A mixed sale's payment_method is 'split' and says nothing about where
        // the money went, so its tenders come off pos_sale_payments.
        $legs = PosSalePayment::query()
            ->whereIn('pos_sale_id', $base()->where('status', '!=', 'voided')->where('payment_method', 'split')->select('id'))
            ->groupBy('method')
            ->selectRaw('method, COALESCE(SUM(amount), 0) as amount')
            ->pluck('amount', 'method');

        // Filtered to one tender, only that tender is counted: "cash only"
        // totals the cash, not the whole of every mixed sale that had some.
        $methods = $method && $method !== 'split' ? [$method] : self::METHODS;

        $tenders = [];
        foreach (self::METHODS as $m) {
            $tenders[$m] = in_array($m, $methods, true)
                ? (float) $row->{"direct_{$m}"} + (float) ($legs[$m] ?? 0)
                : 0.0;
        }

        // Change on a mixed sale always comes out of the drawer.
        if (in_array('cash', $methods, true)) {
            $tenders['cash'] -= (float) $row->split_change;
        }

        $tenders = array_map(fn (float $v) => round($v, 2), $tenders);

        $collected = round($tenders['cash'] + $tenders['card'] + $tenders['bank_transfer'], 2);
        $refunds = $this->refunds($vendorId, $cashierId, $from, $to, $filters);

        return [
            'sales_count'   => (int) $row->sales_count,
            'collected'     => $collected,
            'credit'        => $tenders['debt'],
            'tenders'       => $tenders,
            'vat'           => round((float) $row->vat, 2),
            'discounts'     => round((float) $row->discounts, 2),
            // Whole-sale figure, so it means nothing once a single tender has
            // been picked out of a mixed sale.
            'revenue'       => $method === null
                ? round((float) $row->revenue - ($refunds['total'] ?? 0), 2)
                : null,
            'voided'        => [
                'count' => (int) $row->voided_count,
                'total' => round((float) $row->voided_total, 2),
            ],
            'refunds'       => $refunds,
            'net_collected' => round($collected - ($refunds['money_out'] ?? 0), 2),
        ];
    }

    /**
     * @param  array{method?: ?string, status?: ?string, q?: ?string}  $filters
     * @return array{count: int, total: float, money_out: float}|null
     */
    private function refunds(int $vendorId, int $cashierId, CarbonInterface $from, CarbonInterface $to, array $filters): ?array
    {
        $method = $filters['method'] ?? null;

        if (filled($filters['q'] ?? null)
            || ($filters['status'] ?? null) === 'voided'
            || ($method !== null && ! in_array($method, self::MONEY_METHODS, true))) {
            return null;
        }

        $moneyMethods = "'" . implode("','", self::MONEY_METHODS) . "'";

        $row = PosReturn::query()
            ->where('vendor_id', $vendorId)
            ->where('cashier_id', $cashierId)
            ->whereBetween('created_at', [$from, $to])
            ->when($method, fn (Builder $q) => $q->where('refund_method', $method))
            ->selectRaw("
                COUNT(*) as refund_count,
                COALESCE(SUM(refund_amount), 0) as total,
                COALESCE(SUM(CASE WHEN refund_method IN ({$moneyMethods}) THEN refund_amount ELSE 0 END), 0) as money_out
            ")
            ->toBase()
            ->first();

        return [
            'count'     => (int) $row->refund_count,
            'total'     => round((float) $row->total, 2),
            'money_out' => round((float) $row->money_out, 2),
        ];
    }
}
