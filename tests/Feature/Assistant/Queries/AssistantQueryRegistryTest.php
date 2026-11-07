<?php

declare(strict_types=1);

use App\Actions\Assistant\Queries\SalesSummaryQuery;
use App\Services\Assistant\AssistantQueryRegistry;

test('registry keys returns exactly 20 queries', function () {
    $registry = new AssistantQueryRegistry;
    $keys = $registry->keys();

    expect($keys)->toHaveCount(20)
        ->and($keys)->toContain(
            'sales_summary',
            'sales_by_day',
            'top_products',
            'slow_products',
            'revenue_vs_previous',
            'low_stock',
            'out_of_stock',
            'dead_stock',
            'stock_value',
            'cash_variance',
            'customer_debts',
            'supplier_oweings',
            'vendor_payouts_due',
            'affiliate_commissions',
            'picker_items_out',
            'glossary',
            'profit',
            'customer_history',
            'expenses',
            'cashier_shortages',
        );
});

test('registry get returns the correct query instance', function () {
    $registry = new AssistantQueryRegistry;
    $query = $registry->get('sales_summary');

    expect($query)->toBeInstanceOf(SalesSummaryQuery::class);
});

test('registry get with unknown key throws InvalidArgumentException', function () {
    $registry = new AssistantQueryRegistry;

    expect(fn () => $registry->get('non_existent_key'))
        ->toThrow(InvalidArgumentException::class);
});

test('registry describe returns structured metadata array', function () {
    $registry = new AssistantQueryRegistry;
    $descriptions = $registry->describe();

    expect($descriptions)->toBeArray()
        ->and($descriptions)->toHaveCount(20);

    $first = $descriptions[0];
    expect($first)->toHaveKeys(['key', 'description', 'supports_aggregate', 'default_window_days']);
});
