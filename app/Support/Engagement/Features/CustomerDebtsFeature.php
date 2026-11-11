<?php

declare(strict_types=1);

namespace App\Support\Engagement\Features;

use App\Models\PosCustomerLedgerEntry;
use App\Support\Engagement\Moments\DebtorsPageEmptyVisit;

class CustomerDebtsFeature extends Feature
{
    public function key(): string
    {
        return 'customer_debts';
    }

    public function defaultTitle(): string
    {
        return 'Sell on credit';
    }

    public function defaultDescription(): string
    {
        return 'Let trusted customers pay later, and see exactly who owes you and how much.';
    }

    public function destinationRoute(): ?string
    {
        return 'filament.vendor.resources.customer-debts.index';
    }

    protected function usageSources(): array
    {
        return [
            // A debt is created by a credit sale at the till; created_by is the cashier.
            new UsageSource(
                query: fn () => PosCustomerLedgerEntry::query()->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE),
                userColumns: ['created_by' => 'created_at'],
                model: PosCustomerLedgerEntry::class,
            ),
        ];
    }

    public function moments(): array
    {
        return [DebtorsPageEmptyVisit::class];
    }
}
