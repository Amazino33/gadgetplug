<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\Procurement;

class ProcurementFeature extends Feature
{
    public function key(): string
    {
        return 'procurement';
    }

    public function defaultTitle(): string
    {
        return 'Record a procurement';
    }

    public function defaultDescription(): string
    {
        return 'Record stock you buy from suppliers, with what you paid, so cost and profit stay right.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.resources.procurements.index';
    }

    protected function usageSources(): array
    {
        return [
            new UsageSource(
                query: fn () => Procurement::query(),
                userColumns: ['created_by' => 'created_at'],
                model: Procurement::class,
            ),
        ];
    }
}
