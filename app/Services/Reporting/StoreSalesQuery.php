<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\OrderItem;
use App\Models\OrderItemStoreAllocation;
use App\Models\PosSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// What a store sold, from both channels it can sell through.
//
// Online comes from order_item_store_allocations — the units each branch
// actually supplied — priced at the line's unit_price. POS comes from
// pos_sales.store_id. Reporting one without the other would be worse than no
// per-store figure at all: for a vendor whose counter trade is the bulk of
// the business, online-only per-store sales would show a busy branch as idle.
//
// Revenue recognition matches FinancialReportService's definition exactly —
// orders.revenue_recognized_at, not order date — so a per-store figure and the
// vendor-wide Financial Report can never disagree about what counts as earned.
// Passing a null store gives the vendor-wide total by the same arithmetic.
class StoreSalesQuery
{
    /**
     * @return array{units: int, revenue: float, orders: int}
     */
    public static function totals(int $vendorId, ?int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        $online = self::onlineTotals($vendorId, $storeId, $from, $to);
        $pos = self::posTotals($vendorId, $storeId, $from, $to);

        return [
            'units'   => $online['units'] + $pos['units'],
            'revenue' => $online['revenue'] + $pos['revenue'],
            'orders'  => $online['orders'] + $pos['orders'],
        ];
    }

    /**
     * @return array{units: int, revenue: float, orders: int}
     */
    public static function onlineTotals(int $vendorId, ?int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        // Vendor-wide reads the lines themselves, never the allocations.
        //
        // A line reaches revenue recognition without an allocation whenever it
        // was not reserved through ReserveStockAction, and joining allocations
        // here would drop that revenue out of the total entirely — the owner
        // would see ₦0 on a day they sold. Allocations always sum to the line
        // when they exist, so reading the line is both safe and identical.
        if ($storeId === null) {
            $row = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.vendor_id', $vendorId)
                ->whereNotNull('orders.revenue_recognized_at')
                ->whereBetween('orders.revenue_recognized_at', [$from, $to])
                ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
                ->selectRaw('COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as revenue')
                ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
                ->first();

            return [
                'units'   => (int) $row->units,
                'revenue' => (float) $row->revenue,
                'orders'  => (int) $row->orders,
            ];
        }

        // Per store, only what that branch actually supplied. A line with no
        // allocation belongs to no branch and is therefore absent here while
        // still counted vendor-wide — so the branches can sum to slightly less
        // than the vendor total. That gap is unattributed fulfilment, and
        // showing it is better than inventing a branch to blame it on.
        $row = OrderItemStoreAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_store_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.vendor_id', $vendorId)
            ->whereNotNull('orders.revenue_recognized_at')
            ->whereBetween('orders.revenue_recognized_at', [$from, $to])
            ->where('order_item_store_allocations.store_id', $storeId)
            // Priced on allocated units, not the whole line: a line split
            // across two branches must not credit each with the full amount.
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(order_item_store_allocations.quantity * order_items.unit_price), 0) as revenue')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->first();

        return [
            'units'   => (int) $row->units,
            'revenue' => (float) $row->revenue,
            'orders'  => (int) $row->orders,
        ];
    }

    /**
     * @return array{units: int, revenue: float, orders: int}
     */
    public static function posTotals(int $vendorId, ?int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        // Fully qualified: the units query joins pos_sale_items, which carries
        // its own created_at, and an unqualified column is ambiguous there.
        $sales = PosSale::query()
            ->where('pos_sales.vendor_id', $vendorId)
            ->where('pos_sales.status', '!=', 'voided')
            ->when($storeId, fn ($q) => $q->where('pos_sales.store_id', $storeId))
            ->whereBetween(DB::raw('COALESCE(pos_sales.completed_at, pos_sales.created_at)'), [$from, $to]);

        // Same money definition SalesReportService uses: net of discount and
        // excluding VAT, which is collected for the government rather than
        // earned by the store.
        $money = (clone $sales)
            ->selectRaw('COALESCE(SUM(pos_sales.subtotal - pos_sales.discount_amount), 0) as revenue')
            ->selectRaw('COUNT(*) as orders')
            ->first();

        $units = (int) (clone $sales)
            ->join('pos_sale_items', 'pos_sale_items.pos_sale_id', '=', 'pos_sales.id')
            ->sum('pos_sale_items.quantity');

        return [
            'units'   => $units,
            'revenue' => (float) $money->revenue,
            'orders'  => (int) $money->orders,
        ];
    }
}
