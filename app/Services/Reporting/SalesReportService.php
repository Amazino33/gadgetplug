<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use Carbon\CarbonPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// One definition of what a store earned, used by every dashboard widget and the
// reports page. Before this existed each widget rolled its own query and they
// disagreed: one summed whole-basket order totals (including other vendors'
// items), another summed this vendor's lines, and none of them counted POS
// sales at all.
//
// A store earns from two places that live in separate tables:
//   online → order_items (a marketplace order can span several vendors, so the
//            vendor's share is always the line items, never orders.total_amount)
//   POS    → pos_sales / pos_sale_items
//
// Money definitions used throughout:
//   revenue = net of discounts, EXCLUDING VAT. VAT is collected for the
//             government, not earned, so counting it would overstate the store.
//   cost    = unit_cost captured at the moment of sale, falling back to the
//             product's cost_price today for rows written before that column
//             existed. isEstimated() reports whether any fallback was used, so
//             an approximate figure is never presented as exact.
class SalesReportService
{
    /**
     * Order statuses that represent money actually earned online.
     * 'confirmed'/'shipped' are deliberately excluded — those are not yet paid.
     */
    private const PAID_ORDER_STATUSES = ['paid', 'delivered'];

    // Shared by the totals and the per-day series so the stat cards and the
    // chart can never disagree about what a naira of revenue or cost is.
    private const ONLINE_REVENUE_SQL = 'COALESCE(SUM(order_items.quantity * order_items.unit_price), 0)';

    private const ONLINE_COST_SQL = 'COALESCE(SUM(order_items.quantity * COALESCE(order_items.unit_cost, products.cost_price)), 0)';

    private const POS_REVENUE_SQL = 'COALESCE(SUM(subtotal - discount_amount), 0)';

    private const POS_COST_SQL = 'COALESCE(SUM(pos_sale_items.quantity * COALESCE(pos_sale_items.unit_cost, products.cost_price)), 0)';

    public function summary(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $online = $this->onlineTotals($vendorId, $from, $to);
        $pos = $this->posTotals($vendorId, $from, $to);

        $revenue = $online['revenue'] + $pos['revenue'];
        $cost = $online['cost'] + $pos['cost'];
        $profit = $revenue - $cost;

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $revenue > 0 ? ($profit / $revenue) * 100 : 0.0,
            'orders' => $online['orders'] + $pos['orders'],
            'units' => $online['units'] + $pos['units'],
            'online_revenue' => $online['revenue'],
            'pos_revenue' => $pos['revenue'],
            // True when at least one sold line had no recorded cost, so its
            // profit leans on today's cost price rather than the real one.
            'cost_is_estimated' => $online['estimated'] || $pos['estimated'],
        ];
    }

    public function channelBreakdown(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = $this->summary($vendorId, $from, $to);

        return [
            'Online' => $summary['online_revenue'],
            'POS' => $summary['pos_revenue'],
        ];
    }

    /**
     * Revenue and profit per day across the range, with empty days filled in as
     * zero so the chart shows a continuous line rather than skipping gaps.
     *
     * Grouped in SQL rather than by calling summary() once per day, which would
     * fire hundreds of queries on a long range. The money expressions are shared
     * with the totals above via the same private helpers, so the chart and the
     * stat cards cannot drift apart.
     *
     * @return array{labels: array<string>, revenue: array<float>, profit: array<float>}
     */
    public function dailySeries(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $onlineByDay = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.vendor_id', $vendorId)
            ->whereIn('orders.status', self::PAID_ORDER_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->selectRaw('DATE(orders.created_at) as day')
            ->selectRaw(self::ONLINE_REVENUE_SQL.' as revenue')
            ->selectRaw(self::ONLINE_COST_SQL.' as cost')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $posByDay = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$from, $to])
            ->groupBy(DB::raw('DATE(COALESCE(completed_at, created_at))'))
            ->selectRaw('DATE(COALESCE(completed_at, created_at)) as day')
            ->selectRaw(self::POS_REVENUE_SQL.' as revenue')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $posCostByDay = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->join('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), [$from, $to])
            ->groupBy(DB::raw('DATE(COALESCE(pos_sales.completed_at, pos_sales.created_at))'))
            ->selectRaw('DATE(COALESCE(pos_sales.completed_at, pos_sales.created_at)) as day')
            ->selectRaw(self::POS_COST_SQL.' as cost')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $refundsByDay = PosReturn::query()
            ->where('vendor_id', $vendorId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COALESCE(SUM(refund_amount), 0) as refunds')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $labels = [];
        $revenueSeries = [];
        $profitSeries = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $key = $day->format('Y-m-d');

            $revenue = (float) ($onlineByDay[$key]->revenue ?? 0)
                + max(0.0, (float) ($posByDay[$key]->revenue ?? 0) - (float) ($refundsByDay[$key]->refunds ?? 0));

            $cost = (float) ($onlineByDay[$key]->cost ?? 0)
                + (float) ($posCostByDay[$key]->cost ?? 0);

            $labels[] = $day->format('j M');
            $revenueSeries[] = round($revenue, 2);
            $profitSeries[] = round($revenue - $cost, 2);
        }

        return ['labels' => $labels, 'revenue' => $revenueSeries, 'profit' => $profitSeries];
    }

    /**
     * Every cashier's POS contribution for the period, so the owner can see
     * all their staff's sales in one place rather than only a store-wide total.
     * Online orders have no cashier — a customer places those directly.
     *
     * @return Collection<int, array{cashier_id: int, cashier_name: string, orders: int, revenue: float}>
     */
    public function cashierBreakdown(int $vendorId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PosSale::query()
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), [$from, $to])
            ->groupBy('pos_sales.cashier_id', 'users.name')
            ->selectRaw('pos_sales.cashier_id as cashier_id, users.name as cashier_name')
            ->selectRaw(self::POS_REVENUE_SQL.' as revenue')
            ->selectRaw('COUNT(*) as orders')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'cashier_id' => (int) $row->cashier_id,
                'cashier_name' => $row->cashier_name,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
            ]);
    }

    /**
     * How many online orders exist in this period, by status — including the
     * ones that don't count toward revenue yet ('pending'/'confirmed'/
     * 'cancelled'). Revenue can legitimately read ₦0 for online while orders
     * are still sitting unpaid/undelivered; this is what lets the owner see
     * why, instead of wondering if orders are being missed entirely.
     *
     * @return array<string, int>
     */
    public function onlineOrderStatusBreakdown(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        return Order::query()
            ->whereHas('items', fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as count')
            ->pluck('count', 'status')
            ->all();
    }

    /**
     * Best sellers across both channels, ranked by revenue.
     */
    public function topProducts(int $vendorId, CarbonInterface $from, CarbonInterface $to, int $limit = 10): Collection
    {
        $online = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.vendor_id', $vendorId)
            ->whereIn('orders.status', self::PAID_ORDER_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('order_items.product_id', 'products.name')
            ->selectRaw('order_items.product_id, products.name as name')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.quantity * order_items.unit_price) as revenue')
            ->selectRaw('SUM(order_items.quantity * COALESCE(order_items.unit_cost, products.cost_price)) as cost')
            ->get();

        $pos = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->join('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), [$from, $to])
            ->groupBy('pos_sale_items.product_id', 'products.name')
            ->selectRaw('pos_sale_items.product_id, products.name as name')
            ->selectRaw('SUM(pos_sale_items.quantity) as units')
            ->selectRaw('SUM(pos_sale_items.total) as revenue')
            ->selectRaw('SUM(pos_sale_items.quantity * COALESCE(pos_sale_items.unit_cost, products.cost_price)) as cost')
            ->get();

        return $online->concat($pos)
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                $revenue = (float) $rows->sum('revenue');
                $cost = (float) $rows->sum('cost');

                return [
                    'name' => $rows->first()->name,
                    'units' => (int) $rows->sum('units'),
                    'revenue' => $revenue,
                    'profit' => $revenue - $cost,
                ];
            })
            ->sortByDesc('revenue')
            ->take($limit)
            ->values();
    }

    private function onlineTotals(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        $row = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.vendor_id', $vendorId)
            ->whereIn('orders.status', self::PAID_ORDER_STATUSES)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw(self::ONLINE_REVENUE_SQL.' as revenue')
            ->selectRaw(self::ONLINE_COST_SQL.' as cost')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN 1 ELSE 0 END) as estimated')
            ->first();

        return [
            'revenue' => (float) $row->revenue,
            'cost' => (float) $row->cost,
            'units' => (int) $row->units,
            'orders' => (int) $row->orders,
            'estimated' => ((int) $row->estimated) > 0,
        ];
    }

    private function posTotals(int $vendorId, CarbonInterface $from, CarbonInterface $to): array
    {
        // Sale-level for money: subtotal/discount live on the sale, and a
        // cart-wide discount has no line to belong to.
        // completed_at is what the till recorded; created_at is only when the row
        // reached the server, which for an offline sale can be days later.
        $sales = PosSale::query()
            ->where('vendor_id', $vendorId)
            ->where('status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(completed_at, created_at)'), [$from, $to])
            ->selectRaw(self::POS_REVENUE_SQL.' as revenue')
            ->selectRaw('COUNT(*) as orders')
            ->first();

        // Line-level for cost and units.
        $items = PosSaleItem::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
            ->join('products', 'products.id', '=', 'pos_sale_items.product_id')
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->whereBetween(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), [$from, $to])
            ->selectRaw(self::POS_COST_SQL.' as cost')
            ->selectRaw('COALESCE(SUM(pos_sale_items.quantity), 0) as units')
            ->selectRaw('SUM(CASE WHEN pos_sale_items.unit_cost IS NULL THEN 1 ELSE 0 END) as estimated')
            ->first();

        // Refunds are netted off revenue so a returned sale stops counting as
        // earnings. The cost of returned goods is NOT reversed here, which makes
        // profit conservative rather than flattering in a heavy-refund period.
        $refunds = (float) PosReturn::query()
            ->where('vendor_id', $vendorId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('refund_amount');

        return [
            'revenue' => max(0.0, (float) $sales->revenue - $refunds),
            'cost' => (float) $items->cost,
            'units' => (int) $items->units,
            'orders' => (int) $sales->orders,
            'estimated' => ((int) $items->estimated) > 0,
        ];
    }
}
