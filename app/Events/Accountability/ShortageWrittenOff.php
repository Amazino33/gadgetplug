<?php

namespace App\Events\Accountability;

use Illuminate\Foundation\Events\Dispatchable;

// The business absorbed a shortage — either disposed as a write-off outright, or
// charged and later declared unrecoverable.
//
// direction is 'out': this is the only point in the whole flow where a real loss
// is recognised. lossAtCost is deliberately the *cost* figure and never retail.
// Unrecovered margin is not a loss: it was never earned, so there is nothing to
// lose. Booking it would invent an expense out of a sale that never happened.
//
// Amount is non-negative, per FinancialLedgerEntry's convention.
// See docs/accountability-seam.md.
final readonly class ShortageWrittenOff
{
    use Dispatchable;

    public const DIRECTION = 'out';

    public function __construct(
        public int $vendorId,
        public int $caseId,
        public int $productId,
        public ?int $storekeeperId,
        /**
         * The loss to book, at cost. Net of anything already recovered — a case
         * charged 15,900 (7,710 cost) with 5,000 recovered leaves 2,710 of cost
         * unrecovered, not 7,710. COST-SENSITIVE.
         */
        public float $lossAtCost,
        /** Margin never recovered. Reported for completeness; it is NOT a loss. */
        public float $marginForgone,
        /** 'disposition' when written off outright, 'conversion' when a charge was abandoned. */
        public string $origin,
        public string $occurredAt,
    ) {}

    public function direction(): string
    {
        return self::DIRECTION;
    }
}
