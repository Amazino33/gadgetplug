<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

// New cards slot into the hub by implementing this and getting added to the
// grid's provider list (Phase 2) — nothing about the grid itself changes.
interface ReportCardProvider
{
    /**
     * $storeId narrows the card to one branch. Null — every existing caller —
     * is the vendor-wide figure and behaves exactly as it always has.
     *
     * Not every card can honour it: money and advertising spend have no store
     * dimension in this schema. Those return the vendor-wide number with
     * CardSummary::$vendorWideOnly set, so the UI can say so, rather than
     * quietly presenting the whole business as one branch's performance.
     */
    public function summarize(int $vendorId, ?int $storeId = null): CardSummary;
}
