<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\StoreSettlementStatement;

class SettlementStatementFeature extends Feature
{
    public function key(): string
    {
        return 'settlement_statement';
    }

    public function defaultTitle(): string
    {
        return 'Store settlement statement';
    }

    public function defaultDescription(): string
    {
        return 'Close a period for a branch and get one statement of what came in, what went out and what is owed.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.pages.store-settlement';
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => StoreSettlementStatement::query(),
                userColumns: ['generated_by' => 'created_at'],
                model: StoreSettlementStatement::class,
            ),
        ];
    }
}
