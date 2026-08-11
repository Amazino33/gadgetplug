<?php

namespace App\Events\Accountability;

use Illuminate\Foundation\Events\Dispatchable;

// Money came back against a charged shortage.
//
// direction is always 'in': value returns to the business. Amounts are
// non-negative, as FinancialLedgerEntry requires — the direction carries the
// sign, never the number.
//
// The split is allocated cost-first: a recovery repairs the real economic hole
// (replacing the goods) before it touches the margin that was never earned. So
// recoveredCost fills up to the charge's cost component, and only the excess
// becomes recoveredMargin. Both reduce shrinkage loss rather than being booked
// as income — no sale took place. See docs/accountability-seam.md.
final readonly class ShortageRecovered
{
    use Dispatchable;

    public const DIRECTION = 'in';

    public function __construct(
        public int $vendorId,
        public int $caseId,
        public int $productId,
        public ?int $storekeeperId,
        /** recovery_cash | recovery_salary | recovery_manual */
        public string $entryType,
        /** Non-negative. The full amount recovered by this event. */
        public float $amount,
        /** Portion repairing replacement cost. COST-SENSITIVE. */
        public float $recoveredCost,
        /** Portion beyond cost. COST-SENSITIVE. */
        public float $recoveredMargin,
        /** What remains owed on this case after the event. */
        public float $outstandingAfter,
        public string $occurredAt,
    ) {}

    public function direction(): string
    {
        return self::DIRECTION;
    }
}
