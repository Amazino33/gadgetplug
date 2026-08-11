<?php

namespace App\Events\Accountability;

use Illuminate\Foundation\Events\Dispatchable;

// A shortage was charged to a member of staff.
//
// Carries no `direction` on purpose: nothing has hit the books yet. A charge
// creates a receivable from an employee, not a loss — the stock was already
// written down when the count corrected it, and whether the value is ever lost
// depends on whether it is recovered. The financial layer should record this as
// a claim, not an expense.
//
// All amounts are non-negative, matching FinancialLedgerEntry's convention.
// See docs/accountability-seam.md.
final readonly class ShortageCharged
{
    use Dispatchable;

    public function __construct(
        public int $vendorId,
        public int $caseId,
        public int $productId,
        public int $storekeeperId,
        /** What replacing the goods costs. COST-SENSITIVE. */
        public float $chargedCost,
        /** The margin that would have been earned. COST-SENSITIVE (reveals cost against the total). */
        public float $chargedMargin,
        /** Retail — what the storekeeper is being asked for. Safe to show without the cost permission. */
        public float $chargeTotal,
        /** True when the product had no retail price and the charge is cost only. */
        public bool $priceFallback,
        public string $occurredAt,
    ) {}
}
