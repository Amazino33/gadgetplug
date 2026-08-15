<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSaleItem;
use App\Models\ProcurementLogisticsLeg;
use App\Models\Product;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// Cash-basis P&L: every cost line counts in the period it was actually PAID
// (posted_at), never when it was incurred/recorded — matching the honesty
// label this report renders. Product cost is goods only (order_items.unit_cost,
// falling back to the product's current cost_price when a pre-snapshot row has
// none); inbound logistics, outbound delivery, and every expense category are
// each their own line, so nothing here is folded into anything else and there
// is nothing to double-count.
//
// Revenue is never queried by order-creation date — only by
// orders.revenue_recognized_at, the marker Prompt 4's OrderObserver sets
// exactly once per order, on the transition that actually means money is in
// hand (delivered for POD, paid for prepaid). cancelled/paid_but_failed_stock
// never get that marker at all, so excluding them needs no separate check.
//
// Revenue also includes POS sales — a till sale is money in hand the moment
// it completes, no delivery step to wait for, so recognizedPosSaleItemsQuery()
// below filters on pos_sales.completed_at directly rather than a separate
// recognition marker. "Not voided" (completed/refunded/partial_refund all
// count) matches ProductVelocityService's own definition of a sold unit, so
// revenue and velocity never disagree about a POS sale either.
class FinancialReportService
{
    public function report(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            ...$this->pnl($vendorId, $from, $to),
            'balances' => $this->balances($vendorId, $to),
        ];
    }

    // The P&L lines and net profit for a range, with no balances attached —
    // shared between report() (the period the user picked) and
    // cumulativeProfit() (all-time up to the end of that range), so the two
    // can never drift apart on what "profit" means.
    private function pnl(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $sales = $this->recognizedSales($vendorId, $from, $to);
        $inboundLogistics = $this->postedLegs($vendorId, $from, $to);
        $outboundDelivery = $this->postedDeliveryCost($vendorId, $from, $to);
        $expenses = $this->postedExpenses($vendorId, $from, $to);

        $netProfit = $sales['revenue']
            - $sales['product_cost']
            - $inboundLogistics
            - $outboundDelivery
            - $expenses['advertising']
            - $expenses['logistics_other']
            - $expenses['other'];

        return [
            'revenue'           => $sales['revenue'],
            'product_cost'      => $sales['product_cost'],
            'inbound_logistics' => $inboundLogistics,
            'outbound_delivery' => $outboundDelivery,
            'advertising'       => $expenses['advertising'],
            'other_logistics'   => $expenses['logistics_other'],
            'other_expenses'    => $expenses['other'],
            'net_profit'        => $netProfit,
            // True when any sold line in range had no recorded unit_cost, so
            // product_cost (and therefore net_profit) leans partly on today's
            // cost_price rather than what that unit actually cost at sale time.
            'cost_is_estimated' => $sales['estimated'],
        ];
    }

    // The single source of truth for "which order_items count as delivered" —
    // ProductVelocityService (app/Services/Reporting/ProductVelocityService.php)
    // reuses this exact join/filter for restock velocity, so revenue and
    // velocity can never disagree about what sold. Public and static so it can
    // be called without instantiating the whole report service.
    public static function recognizedOrderItemsQuery(int $vendorId, CarbonInterface $from, CarbonInterface $to)
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.vendor_id', $vendorId)
            ->whereNotNull('orders.revenue_recognized_at')
            ->whereBetween('orders.revenue_recognized_at', [$from, $to]);
    }

    // The single source of truth for "which pos_sale_items count as sold" —
    // ProductVelocityService reuses this exact join/filter for restock
    // velocity and top sellers, so revenue and velocity can never disagree
    // about a POS sale. Public and static for the same reason
    // recognizedOrderItemsQuery() is: callable without instantiating the
    // whole report service.
    public static function recognizedPosSaleItemsQuery(int $vendorId, CarbonInterface $from, CarbonInterface $to)
    {
        return PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween('pos_sales.completed_at', [$from, $to]);
    }

    private function recognizedSales(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $online = self::recognizedOrderItemsQuery($vendorId, $from, $to)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as revenue')
            ->selectRaw('COALESCE(SUM(order_items.quantity * COALESCE(order_items.unit_cost, products.cost_price)), 0) as product_cost')
            ->selectRaw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN 1 ELSE 0 END) as estimated_lines')
            ->first();

        $pos = self::recognizedPosSaleItemsQuery($vendorId, $from, $to)
            ->join('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->selectRaw('COALESCE(SUM(pos_sale_items.total), 0) as revenue')
            ->selectRaw('COALESCE(SUM(pos_sale_items.quantity * COALESCE(pos_sale_items.unit_cost, products.cost_price)), 0) as product_cost')
            ->selectRaw('SUM(CASE WHEN pos_sale_items.unit_cost IS NULL THEN 1 ELSE 0 END) as estimated_lines')
            ->first();

        return [
            'revenue'      => (float) $online->revenue + (float) $pos->revenue,
            'product_cost' => (float) $online->product_cost + (float) $pos->product_cost,
            'estimated'    => ((int) $online->estimated_lines) > 0 || ((int) $pos->estimated_lines) > 0,
        ];
    }

    private function postedLegs(int $vendorId, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) ProcurementLogisticsLeg::query()
            ->whereHas('procurement', fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$from, $to])
            ->sum('amount');
    }

    private function postedDeliveryCost(int $vendorId, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Order::query()
            ->whereHas('items', fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$from, $to])
            ->sum('delivery_cost');
    }

    private function postedExpenses(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = Expense::query()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('posted_at')
            ->whereBetween('posted_at', [$from, $to])
            ->groupBy('category')
            ->selectRaw('category, SUM(amount) as total')
            ->pluck('total', 'category');

        return [
            'advertising'     => (float) ($totals['advertising'] ?? 0),
            'logistics_other' => (float) ($totals['logistics_other'] ?? 0),
            'other'           => (float) ($totals['other'] ?? 0),
        ];
    }

    // "As of end of range" throughout — not module-level, not cached; each
    // call re-derives from the live ledger, same discipline as
    // FinancialAccount::balance() itself.
    private function balances(int $vendorId, CarbonInterface $asOf): array
    {
        $bank = FinancialAccount::where('vendor_id', $vendorId)->where('type', 'bank')->first();
        $cash = FinancialAccount::where('vendor_id', $vendorId)->where('type', 'cash')->first();

        $bankBalance = $bank?->balance($asOf) ?? 0.0;
        $cashBalance = $cash?->balance($asOf) ?? 0.0;

        $inventoryValue = (float) Product::where('vendor_id', $vendorId)
            ->sum(DB::raw('stock_quantity * COALESCE(cost_price, 0)'));

        $initialCapital = (float) (Vendor::find($vendorId)?->initial_capital ?? 0);

        return [
            'bank'               => $bankBalance,
            'cash'               => $cashBalance,
            'total_liquid'       => $bankBalance + $cashBalance,
            'inventory_value'    => $inventoryValue,
            'initial_capital'    => $initialCapital,
            'cumulative_profit'  => $this->cumulativeProfit($vendorId, $asOf),
        ];
    }

    // All-time profit up to the end of the selected range — separate from the
    // period P&L, for the "are we up from where we began" read alongside
    // inventory value and initial capital.
    private function cumulativeProfit(int $vendorId, CarbonInterface $asOf): float
    {
        return $this->pnl($vendorId, CarbonImmutable::createFromTimestamp(0), $asOf)['net_profit'];
    }
}
