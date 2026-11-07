<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Actions\Assistant\Queries\AffiliateCommissionsQuery;
use App\Actions\Assistant\Queries\BaseAssistantQuery;
use App\Actions\Assistant\Queries\CashierShortageQuery;
use App\Actions\Assistant\Queries\CashVarianceQuery;
use App\Actions\Assistant\Queries\CustomerDebtsQuery;
use App\Actions\Assistant\Queries\CustomerHistoryQuery;
use App\Actions\Assistant\Queries\DeadStockQuery;
use App\Actions\Assistant\Queries\ExpenseSummaryQuery;
use App\Actions\Assistant\Queries\GlossaryQuery;
use App\Actions\Assistant\Queries\LowStockQuery;
use App\Actions\Assistant\Queries\OutOfStockQuery;
use App\Actions\Assistant\Queries\PickerItemsOutQuery;
use App\Actions\Assistant\Queries\ProfitQuery;
use App\Actions\Assistant\Queries\RevenueVsPreviousQuery;
use App\Actions\Assistant\Queries\SalesByDayQuery;
use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Actions\Assistant\Queries\SlowProductsQuery;
use App\Actions\Assistant\Queries\StockValueQuery;
use App\Actions\Assistant\Queries\SupplierOweingsQuery;
use App\Actions\Assistant\Queries\TopProductsQuery;
use App\Actions\Assistant\Queries\VendorPayoutsDueQuery;
use InvalidArgumentException;

class AssistantQueryRegistry
{
    /**
     * Complete catalogue of all 16 assistant domain queries.
     *
     * @var array<string, array{class: class-string<BaseAssistantQuery>, description: string, supports_aggregate: bool, default_window_days: int}>
     */
    private const QUERIES = [
        'sales_summary' => [
            'class' => SalesSummaryQuery::class,
            'description' => 'Revenue and orders for store.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'sales_by_day' => [
            'class' => SalesByDayQuery::class,
            'description' => 'Daily revenue and order breakdown.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'top_products' => [
            'class' => TopProductsQuery::class,
            'description' => 'Top 5 products by revenue.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'slow_products' => [
            'class' => SlowProductsQuery::class,
            'description' => 'Bottom 5 products by sales.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'revenue_vs_previous' => [
            'class' => RevenueVsPreviousQuery::class,
            'description' => 'Compare revenue to prior period.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'low_stock' => [
            'class' => LowStockQuery::class,
            'description' => 'Products below stock threshold.',
            'supports_aggregate' => false,
            'default_window_days' => 0,
        ],
        'out_of_stock' => [
            'class' => OutOfStockQuery::class,
            'description' => 'Products with zero stock.',
            'supports_aggregate' => false,
            'default_window_days' => 0,
        ],
        'dead_stock' => [
            'class' => DeadStockQuery::class,
            'description' => 'Items with no sales in 30 days.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'stock_value' => [
            'class' => StockValueQuery::class,
            'description' => 'Inventory valuation at cost.',
            'supports_aggregate' => true,
            'default_window_days' => 0,
        ],
        'cash_variance' => [
            'class' => CashVarianceQuery::class,
            'description' => 'Cash discrepancies vs expected.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'customer_debts' => [
            'class' => CustomerDebtsQuery::class,
            'description' => 'Customer debts and debtor count.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'supplier_oweings' => [
            'class' => SupplierOweingsQuery::class,
            'description' => 'Unpaid supplier procurements.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'vendor_payouts_due' => [
            'class' => VendorPayoutsDueQuery::class,
            'description' => 'Pending and paid payouts.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'affiliate_commissions' => [
            'class' => AffiliateCommissionsQuery::class,
            'description' => 'Affiliate sales commissions.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'picker_items_out' => [
            'class' => PickerItemsOutQuery::class,
            'description' => 'Items released to pickers.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'glossary' => [
            'class' => GlossaryQuery::class,
            'description' => 'Business terms and definitions.',
            'supports_aggregate' => true,
            'default_window_days' => 0,
        ],
        'profit' => [
            'class' => ProfitQuery::class,
            'description' => 'Net profit: revenue minus cost.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'customer_history' => [
            'class' => CustomerHistoryQuery::class,
            'description' => 'Customer purchase history.',
            'supports_aggregate' => true,
            'default_window_days' => 30,
        ],
        'expenses' => [
            'class' => ExpenseSummaryQuery::class,
            'description' => 'Total till expenses in period.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
        'cashier_shortages' => [
            'class' => CashierShortageQuery::class,
            'description' => 'Cashier till cash variances.',
            'supports_aggregate' => false,
            'default_window_days' => 30,
        ],
    ];

    /**
     * @return array<string, array{class: class-string<BaseAssistantQuery>, description: string, supports_aggregate: bool, default_window_days: int}>
     */
    public function all(): array
    {
        return self::QUERIES;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys(self::QUERIES);
    }

    public function get(string $key): BaseAssistantQuery
    {
        if (! array_key_exists($key, self::QUERIES)) {
            throw new InvalidArgumentException("Unknown assistant query key: [{$key}].");
        }

        $className = self::QUERIES[$key]['class'];

        return app($className);
    }

    /**
     * Returns structured metadata for all registered queries, designed for AI prompt injection.
     *
     * @return array<int, array{key: string, description: string, supports_aggregate: bool, default_window_days: int}>
     */
    public function describe(): array
    {
        $descriptions = [];

        foreach (self::QUERIES as $key => $meta) {
            $descriptions[] = [
                'key' => $key,
                'description' => $meta['description'],
                'supports_aggregate' => $meta['supports_aggregate'],
                'default_window_days' => $meta['default_window_days'],
            ];
        }

        return $descriptions;
    }
}
