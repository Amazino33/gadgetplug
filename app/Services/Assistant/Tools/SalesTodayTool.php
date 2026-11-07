<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Vendor\Pages\SalesReport;
use App\Models\Store;
use App\Services\Assistant\AssistantContext;
use App\Services\Assistant\Guards\DateRange;
use App\Services\Assistant\Guards\StoreScope;
use App\Services\Pos\CashierSalesHistory;
use App\Services\Reporting\SalesReportService;
use App\Support\Naira;
use Throwable;

/**
 * Today's sales so far — the same figure as the dashboard's "Today's Sales"
 * tile, from the same SalesReportService::summary() call.
 *
 * Two audiences, matching the panel:
 *   view_inventory_reports → the store's (or stores') takings, per branch and
 *                            combined. The gate the dashboard tile and the
 *                            Sales Report page already use.
 *   access_pos only        → the caller's own sales, from CashierSalesHistory
 *                            — what the till's My Sales screen shows them.
 * Profit, cost and margin are included only for view_cost_price; the
 * registry's FieldStripper removes them for everyone else regardless.
 */
final class SalesTodayTool extends AssistantTool
{
    public function __construct(
        private SalesReportService $reports,
        private CashierSalesHistory $cashierSales,
    ) {}

    public function name(): string
    {
        return 'sales_today';
    }

    public function description(): string
    {
        return "Today's sales so far, where today is the business's own day (Africa/Lagos midnight to now). "
            ."Same figures as the dashboard \"Today's Sales\" tile: revenue is net of discounts and excludes VAT, "
            .'and covers both till (POS) and online sales. Returns the total, the number of sales and units, '
            .'a POS/online split, and a per-store breakdown, plus profit and margin when the user may see cost. '
            .'Leave "store" empty for every store the user can access. Users who may only see their own sales get '
            .'their own till sales instead, and the result says so.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'store' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'A store (branch) name as the user said it, or "all". Omit for all stores the user can access.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function allows(AssistantContext $context): bool
    {
        return $context->can('view_inventory_reports') || $context->can('access_pos');
    }

    public function handle(array $input, AssistantContext $context): ToolResult
    {
        $range = DateRange::today();

        if (! $context->can('view_inventory_reports')) {
            return $this->ownSales($input, $context, $range);
        }

        $scope = StoreScope::resolve($context, $input['store'] ?? null);
        $vendorId = $context->vendor->id;

        $summaries = $scope->stores
            ->mapWithKeys(fn (Store $store) => [$store->id => $this->reports->summary($vendorId, $range->from(), $range->to(), $store->id)])
            ->all();

        $byStore = $scope->stores
            ->map(fn (Store $store) => ['store' => $store->name] + $this->figures($summaries[$store->id]))
            ->values()
            ->all();

        if ($scope->isWholeBusiness && $scope->isCombined) {
            // Exactly the dashboard's "All Stores" call: vendor-wide, no store filter.
            $totals = $this->figures($this->reports->summary($vendorId, $range->from(), $range->to(), null));
        } elseif ($scope->isCombined) {
            $totals = $this->figures($this->sumSummaries(array_values($summaries)));
        } else {
            $totals = $byStore[0];
            unset($totals['store']);
        }

        return ToolResult::make([
            'period' => $range->describe(),
            'scope' => $scope->label(),
            'whose' => 'all staff',
            'totals' => $totals,
            'by_store' => $scope->isCombined ? $byStore : [],
            'notes' => array_values(array_filter([
                'Revenue is net of discounts and excludes VAT — the dashboard definition.',
                ($totals['cost_is_estimated'] ?? false) && $context->canSeeCost()
                    ? "Some sold lines had no recorded cost, so profit uses today's cost price for them and is an estimate."
                    : null,
            ])),
        ], $this->link($context));
    }

    /**
     * A till user without report access: their own sales, as My Sales shows
     * them. Naming a store they can't see is still refused, the same way.
     */
    private function ownSales(array $input, AssistantContext $context, DateRange $range): ToolResult
    {
        if (filled($input['store'] ?? null)) {
            StoreScope::resolve($context, $input['store']);
        }

        $s = $this->cashierSales->summary($context->vendor->id, $context->user->id, $range->from(), $range->to());

        return ToolResult::make([
            'period' => $range->describe(),
            'scope' => 'Your own sales only, every store you rang them in',
            'whose' => "only the asking user's own till sales — this user may not see other staff's sales or store totals",
            'totals' => [
                'revenue' => (float) ($s['revenue'] ?? 0),
                'revenue_display' => Naira::format($s['revenue'] ?? 0, '₦0'),
                'sales_count' => $s['sales_count'],
                'collected_display' => Naira::format($s['collected'], '₦0'),
                'on_credit_display' => Naira::format($s['credit'], '₦0'),
                'by_tender_display' => array_map(fn ($v) => Naira::format($v, '₦0'), $s['tenders']),
                'voided_count' => $s['voided']['count'],
                'refunds_count' => $s['refunds']['count'] ?? 0,
                'refunds_total_display' => Naira::format($s['refunds']['total'] ?? 0, '₦0'),
            ],
            'notes' => ["Revenue is net of discounts and refunds and excludes VAT, as on the till's My Sales screen."],
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary  SalesReportService::summary() output.
     * @return array<string, mixed>
     */
    private function figures(array $summary): array
    {
        return [
            'revenue' => round((float) $summary['revenue'], 2),
            'revenue_display' => Naira::format($summary['revenue'], '₦0'),
            'sales_count' => (int) $summary['orders'],
            'units' => $summary['units'],
            'pos_revenue_display' => Naira::format($summary['pos_revenue'], '₦0'),
            'online_revenue_display' => Naira::format($summary['online_revenue'], '₦0'),
            'cost' => round((float) $summary['cost'], 2),
            'cost_display' => Naira::format($summary['cost'], '₦0'),
            'profit' => round((float) $summary['profit'], 2),
            'profit_display' => Naira::format($summary['profit'], '₦0'),
            'margin_percent' => round((float) $summary['margin'], 1),
            'cost_is_estimated' => (bool) $summary['cost_is_estimated'],
        ];
    }

    /**
     * Branches added together for a user who sees some, not all, of them.
     * Summed in code from the same per-store summaries shown beside it, so
     * the total is by construction the sum of its rows.
     *
     * @param  array<int, array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private function sumSummaries(array $summaries): array
    {
        $total = ['revenue' => 0.0, 'cost' => 0.0, 'orders' => 0, 'units' => 0, 'pos_revenue' => 0.0, 'online_revenue' => 0.0, 'cost_is_estimated' => false];

        foreach ($summaries as $s) {
            foreach (['revenue', 'cost', 'orders', 'units', 'pos_revenue', 'online_revenue'] as $k) {
                $total[$k] += $s[$k];
            }
            $total['cost_is_estimated'] = $total['cost_is_estimated'] || $s['cost_is_estimated'];
        }

        $total['profit'] = $total['revenue'] - $total['cost'];
        $total['margin'] = $total['revenue'] > 0 ? ($total['profit'] / $total['revenue']) * 100 : 0.0;

        return $total;
    }

    private function link(AssistantContext $context): ?string
    {
        try {
            return SalesReport::getUrl(panel: 'vendor', tenant: $context->vendor);
        } catch (Throwable) {
            return null;
        }
    }
}
