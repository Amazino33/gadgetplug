<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\CashSubmission;

class CashSubmissionsFeature extends Feature
{
    public function key(): string
    {
        return 'cash_submissions';
    }

    public function defaultTitle(): string
    {
        return 'Cash submissions';
    }

    public function defaultDescription(): string
    {
        return 'See how much each storekeeper has handed over, and what is still pending.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.resources.cash-submissions.index';
    }

    protected function usageSources(): array
    {
        return [
            // Storekeepers use it by submitting; owners and managers by confirming.
            // Opening the page is not recorded anywhere, so it does not count.
            new UsageSource(
                query: fn () => CashSubmission::query(),
                userColumns: ['submitted_by' => 'created_at', 'received_by' => 'confirmed_at'],
                model: CashSubmission::class,
            ),
        ];
    }
}
