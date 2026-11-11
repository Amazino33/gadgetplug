<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\Expense;
use App\Models\TillExpense;

class ExpensesFeature extends Feature
{
    public function key(): string
    {
        return 'expenses';
    }

    public function defaultTitle(): string
    {
        return 'Record expenses';
    }

    public function defaultDescription(): string
    {
        return 'Log what the business spends, from rent to fuel, so your profit is the real profit.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.resources.expenses.index';
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => Expense::query(),
                userColumns: ['created_by' => 'created_at'],
                model: Expense::class,
            ),
            // Spending out of the till drawer counts too.
            new UsageSource(
                query: fn () => TillExpense::query(),
                userColumns: ['logged_by' => 'created_at'],
                model: TillExpense::class,
            ),
        ];
    }
}
