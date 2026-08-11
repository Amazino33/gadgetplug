<?php

declare(strict_types=1);

namespace App\Services\Reporting\Cards;

// New cards slot into the hub by implementing this and getting added to the
// grid's provider list (Phase 2) — nothing about the grid itself changes.
interface ReportCardProvider
{
    public function summarize(int $vendorId): CardSummary;
}
