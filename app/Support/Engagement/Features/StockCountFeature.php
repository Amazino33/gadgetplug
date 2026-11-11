<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\PhysicalStockCount;

class StockCountFeature extends Feature
{
    public function key(): string
    {
        return 'stock_count';
    }

    public function defaultTitle(): string
    {
        return 'Count your stock';
    }

    public function defaultDescription(): string
    {
        return 'Count what is on the shelf and see straight away what is missing.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.pages.blind-count';
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => PhysicalStockCount::query(),
                userColumns: ['counted_by' => 'created_at'],
                model: PhysicalStockCount::class,
            ),
        ];
    }
}
