<?php

declare(strict_types=1);

namespace App\Support\Engagement\Moments;

use App\Models\PosCustomerLedgerEntry;
use App\Support\Engagement\TipContext;

/**
 * Opened the customer debts list while the business has no debts at all.
 * A page visit, not a save, so it is detected while the tip is being chosen.
 */
class DebtorsPageEmptyVisit extends Moment
{
    public const KEY = 'debtors_page_empty_visit';

    public const ROUTE = 'filament.vendor.resources.customer-debts.index';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Opened the customer debts page with no debts yet';
    }

    public function detectOnRender(TipContext $context): ?int
    {
        if ($context->routeName !== self::ROUTE || ! $context->vendor) {
            return null;
        }

        $hasDebts = PosCustomerLedgerEntry::query()
            ->where('vendor_id', $context->vendor->id)
            ->where('direction', PosCustomerLedgerEntry::DIRECTION_CHARGE)
            ->exists();

        return $hasDebts ? null : 1;
    }
}
